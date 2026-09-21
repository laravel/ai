# Gotchas

Known rough edges, with the code that causes them. Each entry names what is surprising,
where it is decided, and what it costs.

## OpenAI `store` still couples transport to request shape

`config/ai.php` ships `'store' => env('OPENAI_STORE', true)` (and `AZURE_OPENAI_STORE`).
`isStateless()` (`BuildsTextRequests:149`) is true only when that is explicitly `false`.
Since #1034 it no longer decides what is persisted: `extractReplayBlocks()` runs on every
response, and reasoning models always get `include: ["reasoning.encrypted_content"]`
(`BuildsTextRequests:120`), whatever `store` is. What it still switches:

| Site | `store: true` (default) | `store: false` |
|---|---|---|
| `BuildsTextRequests:116` | `store` omitted, OpenAI retains server-side | sends `store: false` |
| `HandlesTextSteps:82` | continuation via `previous_response_id` | full message history each request |
| `MapsTools:61` | `ToolSearch` allowed | `ToolSearch` throws a `LogicException` |

So the client now always keeps its own copy, matching the Vercel AI SDK, but in-run
continuation and the `ToolSearch` guard still hang off the same flag as server-side
retention. There is no way to keep `store: true` and still replay full history.

## `previous_response_id` is never persisted

`StepResponse::$continuationToken` is a local in `TextGenerationLoop::generate()` and
`stream()` (`:99`, `:225`), updated per step (`:192`, `:379`) and discarded when the call
returns. `Step` has no field for it, so `stepsFor()` cannot write it and `meta` does not
carry it.

Within one `prompt()` the continuation works. Across turns the loop restarts at `null` and
rebuilds history from the `steps` column, so the server-side handle is never reused across
turns even though every request on the default config pays to create one.

## Provider-hosted tools are not persisted

A `Providers\Tools\ProviderTool` (`WebSearch`, `WebFetch`, `FileSearch`, code execution)
has no `handle()`, so `TextGenerationLoop` never invokes it and it never reaches
`tool_calls` or `tool_results`. Its only home is `steps[].replay_blocks`, which is emptied
once the turn completes.

`Streaming\Events\ProviderToolEvent` is emitted straight from the gateway SSE loops
(`OpenAi/HandlesTextGeneration:184`, `:199` and the Anthropic, Gemini and xAI equivalents)
and rendered by the stream protocols. Nothing persists it — `StepResponse` has no field
for it, and the non-streaming path never produces one.

Every gateway passes its raw output through (`Anthropic/ParsesTextResponses:95`,
`OpenAi/ParsesTextResponses:132` keeps every `output` item), so provider-tool blocks do land
in `replay_blocks` while a turn is paused, and nothing reads them back for display.

Practical effect for a database-backed chat UI: a turn that ran a web search reloads as
prose only. There is no `provider_tools` key and no accessor to render "searched the web
for X" from.

## Raw provider blocks do not cross providers

`replay_blocks` holds whatever the provider emitted, so it cannot be replayed to a
different one — `withoutForeignReplayBlocks()` (`ResumesToolApprovals:36`) strips foreign
state on purpose.
Storing more raw blocks therefore does not make a conversation portable across a model or
provider switch. That needs a provider-neutral record shaped like `tool_calls` and
`tool_results`, which already survive a switch because they are DTOs rather than payloads.

## Raw blocks are stored only while a turn is paused

`stepsFor()` writes `replay_blocks` only when the response has pending approvals, and
`forgetReplayBlocks()` empties them on every paused row of the conversation once a turn
completes. The read path replays whatever a row holds, so a completed turn rebuilds
through generic mapping: the model sees its own text and tool calls but not the thinking,
encrypted reasoning or provider-tool blocks behind them. The exception is Gemini's
`thought_signature`, which stays on `steps[].tool_calls[]` because Gemini 3 400s on a
prior-turn `functionCall` without one (#837).

This is deliberate, and the provider docs (checked 2026-09-18) back it. Every hard rule is
scoped to the *open* turn:

| Provider | Required | Across completed turns |
|---|---|---|
| Anthropic | thinking blocks back, unmodified, while the tool-use turn is open (400 otherwise) | "Recommended: pass everything back. Allowed: omit prior turns' thinking." Opus 4.5+, Sonnet 4.6+ and Fable/Mythos keep them and bill them as input; Haiku and older strip them server-side. |
| Gemini 3 | `thoughtSignature` on every `functionCall` part of every step in the current turn (400 otherwise) | "Must return to maintain full context", but validation is current-turn only. Docs ask for consistency: send full history or none. |
| OpenAI | reasoning items back between function calls in one response chain | `reasoning.context` defaults to `all_turns` on GPT-5.6, `current_turn` on older models. Stateless callers get the benefit only by replaying every output item with `encrypted_content`. Cross-family reasoning is dropped silently. |

So replaying a completed turn is never needed for correctness. Its benefit is model quality
and cache hits on keep-all Anthropic models and GPT-5.6. Its costs: input tokens for blocks
the provider may discard, state that cannot cross a provider switch, and one new hazard:
Anthropic's preserved-thinking prefix check binds a thinking block to the exact `system`,
`tools` and preceding messages. Replaying old blocks after an agent's instructions or tool
list changed is a 400 (or a silent drop) on accounts created after 2026-08-31, and will be
for everyone later. Dynamic `instructions()` makes that the common case, not the edge.

The rule Anthropic *does* enforce cuts the other way, and `MapsMessages` respects it: a
completed turn whose thinking we no longer hold replays with no thinking block at all, rather
than one rebuilt from a `reasoningSummary` we have no signature for.

OpenAI's encrypted reasoning follows the same rule: `toolCallsFor()` strips `reasoning_id`,
`reasoning_summary` and `reasoning_encrypted_content` from stored tool calls, so nothing of
it survives a completed turn.

## Reloaded history has no step boundaries

Live, `VercelDataProtocol` emits `start-step` / `finish-step` around each round-trip,
derived from `StreamStart` events (`:55`) rather than from `Step` objects. The two notions
of a step agree only because `TextGenerationLoop:502` yields one `StreamStart` per
iteration; nothing enforces it.

On reload, `Vercel::toUiMessages()` flattens the whole turn into one UI message. It reads
`steps` for the reasoning, so each step's reasoning survives as its own part, but the tool
parts still come from the flattened `tool_calls` accessor with no `start-step` boundary
between them. So a turn that streamed as three step blocks comes back as one message.

## Encrypted reasoning survives only where it hangs off a tool call

#1020 populates `StepResponse::$reasoning` on every path, and the `steps` column keeps it
per step, so a reloaded turn renders the same reasoning it streamed. That part is display
state: #1020 explicitly dropped cross-turn reasoning replay, so the readable summary is
never sent back to the provider.

The encrypted counterpart is provider-dependent. Gemini's `thought_signature` (#837) stays
on the stored tool call and `Gemini/Concerns/MapsMessages:80` replays it, because Gemini
validates it on prior-turn `functionCall` parts. OpenAI's `reasoning_encrypted_content` is
stripped, since OpenAI never errors on its absence. A text-only Gemini turn still drops the
signature on its final text part, which Gemini does not validate.

## Conversation history is trimmed by row, not by message

`RemembersConversations::messages()` loads the newest `maxConversationMessages()` rows,
defaulting to 100. Rows are trimmed before reconstruction, so a multi-step turn can expand
well past 100 messages, and the oldest row in the window may be an assistant turn whose
user message was cut.
