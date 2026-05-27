# Single-Turn Gateway Refactor Direction

This note captures the intended direction after the `TextGenerationLoop`
foundation branch and the follow-up review pass that hardened it.
The goal is to evaluate the shape and amount of code needed to convert one
provider at a time, without adding broad compatibility machinery too early.

## Target Shape

Long term, text generation should be split like this:

```text
Agent / provider text runtime
    -> TextGenerationLoop
        -> SingleTurnTextGateway
            -> provider API
```

The gateway should become a provider API adapter. It should build one request,
send one model turn, parse one response, and yield one stream turn. It should not
own the multi-step loop, tool execution, max-step enforcement, step aggregation,
or final response assembly.

This mirrors the Vercel AI SDK shape: providers/models normalize model access,
while AI SDK Core owns higher-level orchestration such as tool calling, steps,
stop conditions, streaming, and telemetry.

## Foundation In Place

The foundation is now a small, audited set of pieces:

- `Gateway/TextGenerationLoop` — the orchestrator. Owns multi-step looping,
  tool execution, message accumulation, accumulated usage, final response
  assembly, and the single public `StreamEnd` emitted at the end of streaming.
- `Gateway/SingleTurnResponse` — outcome of one non-streaming provider turn.
  Carries provider-specific replay data (`responseId`, `providerContentBlocks`)
  as an opaque ferry the loop hands back to the gateway on the next turn.
- `Gateway/SingleTurnStreamEnd` — internal sentinel a gateway yields at the
  end of one streaming turn. **Not** a `StreamEvent`; never reaches consumers.
  Its `reason` is typed as `FinishReason` (the enum), not a magic string;
  the loop converts to string only when synthesizing the public `StreamEnd`.
  The loop consumes it for bookkeeping and synthesizes the public `StreamEnd`
  once the loop is complete.
- `Gateway/StepContext` — per-turn hints the loop passes into the gateway
  (`stepNumber`, `isFinalStep`, `previousResponseId`). Flat fields by design;
  future continuation state should be a typed `?SingleTurnContinuation`, not
  a `mixed` bag.
- `Contracts/Gateway/SingleTurnTextGateway` — opt-in contract. Marked
  `@internal` while the shape is still being validated.
- `Gateway/Concerns/DelegatesToTextGenerationLoop` — shared trait that wires
  the multi-step loop on top of single-turn methods. Provides `generateText`,
  `streamText`, `onToolInvocation`, and `buildTextGenerationLoop`. A migrating
  gateway adds one `use` statement and implements only `generateSingleTurn` and
  `streamSingleTurn`. The trait reads `$this->events` to construct the loop
  — the using class must declare a `protected Dispatcher $events` property
  (documented on the trait via `@property`).

OpenAI and Azure OpenAI are the reference conversions. They:

- implement `SingleTurnTextGateway`
- `use DelegatesToTextGenerationLoop`
- no longer `use InvokesTools` — the loop owns tool execution
- have empty constructors; no `initializeToolCallbacks()` round-trip

## Public Surface Stays Clean

The loop is the only place that emits a public `StreamEnd`. Its fields are
`id`, `reason`, `usage`, `timestamp` — nothing else. Provider-specific
continuation state (`responseId`, `providerContentBlocks`) lives on
`SingleTurnResponse` / `SingleTurnStreamEnd` and never leaks into events.

`Usage` is accumulated across every intercepted turn-end, so multi-step
streamed responses report total tokens — not just the last turn's.

`maxSteps` from `TextGenerationOptions` is clamped to at least `1`, so the
final-response assembly always has a step to work with.

If a gateway terminates a stream early (e.g. SSE error → `return` before any
`SingleTurnStreamEnd`), the loop does **not** synthesize a phantom `StreamEnd`.
Error events stop the stream cleanly.

## What The Review + Simplifier Pass Tightened

The foundation has been through a multi-agent review (reuse, quality,
efficiency) and a laravel-simplifier pass. Concretely:

- **`SingleTurnStreamEnd::$reason` is `FinishReason`, not `string`.**
  Gateways yield the enum; the loop converts to `string` only at the public
  `StreamEnd` boundary. Stops magic-string round-trips inside the loop.
- **Trait `$events` requirement documented.**
  `DelegatesToTextGenerationLoop` carries `@property Dispatcher $events` and
  a docblock note. New providers won't be surprised by the implicit contract.
- **WHAT-comments and narrative docblocks stripped.**
  `TextGenerationLoop`, `SingleTurnResponse`, `SingleTurnStreamEnd`,
  `StepContext`, `SingleTurnTextGateway`, `StreamEnd`, and the OpenAI
  `HandlesTextStreaming` / `ParsesTextResponses` traits no longer carry
  comments that just narrate types. Surviving comments are load-bearing:
  the `1.5x multiplier` heuristic, the `providerContentBlocks` opaque-ferry
  contract, the trait `$events` requirement, and `@internal` tags.
- **`@internal` is intentionally aspirational.**
  `SingleTurnTextGateway` is marked `@internal` even though public gateways
  implement it, because the contract is migration scaffolding — it will fold
  into `TextGateway` once consolidation is decided. Static analysis tools
  may complain; that's the desired signal.

### Findings deferred (not done in this branch)

- **Repo-wide `strtolower((string) Str::uuid7())` helper.** Pattern is
  duplicated across ~9 streaming traits. Out of scope for the single-turn
  foundation; tackle in a dedicated event-id cleanup PR.
- **Tool execution concurrency.** `executeToolCalls()` runs tools serially.
  PHP has no native concurrency; would need `Concurrency::run()` or a
  coroutine runner. Worth a separate design discussion, not a code change.
- **`collect()` wrapping in `AssistantMessage` / `ToolResultMessage`
  constructors.** Bounded by tool calls per turn; not on a hot path large
  enough to matter.

### Invariants the review verified

These are now part of the OpenAI/Azure test surface and must remain green
for every future provider conversion:

- streamed `Usage` accumulated across multi-step turns (not just last turn)
- `maxSteps: 0` (and negative) clamped to one provider turn
- streaming error path stops cleanly (no phantom `StreamEnd`)
- `previous_response_id` threaded through `StepContext`, not `StreamEnd`

## What Not To Do In This Draft

Do not add mixed runtime branching like:

```php
if ($gateway instanceof SingleTurnTextGateway) {
    yield from (new TextGenerationLoop(...))->stream(...);
}
```

That hides the real migration cost. This branch is for proving the provider
refactor shape and reviewing the diff needed per provider, not for supporting
every old and new gateway path at the same time.

The draft can temporarily break unconverted providers while the architecture is
being evaluated. OpenAI and Azure OpenAI are the exceptions: they are the
reference implementations and must keep existing behavior.

## Compatibility Rule

For OpenAI and Azure OpenAI:

- `Agent::prompt()` and `Agent::stream()` must keep working.
- `TextGateway::generateText()` and `TextGateway::streamText()` keep the
  same public signatures while this package still exposes them. These now
  live in `DelegatesToTextGenerationLoop`, so the gateway no longer carries
  duplicate copies.
- Tool-loop behavior must remain the same: tool calls execute, follow-up
  requests are sent, steps and usage are accumulated, and streaming still emits
  the existing public events.
- OpenAI/Azure continuation via `previous_response_id` must continue working
  (threaded through `StepContext`, not through `StreamEnd`).

For other providers during the draft:

- It is acceptable for tests to fail until the provider is migrated.
- It is acceptable to temporarily remove duplicated gateway-owned loop code.
- The important review artifact is the provider conversion diff.

Before release, all providers must either be migrated or wrapped by an explicit
compatibility adapter. The released public API should not regress.

## Recommended Refactor Sequence

1. Keep the `TextGenerationLoop` foundation as-is.
2. Pick one provider per PR. xAI is a natural next step since it also uses
   `previous_response_id`; after that, Anthropic exercises the
   `providerContentBlocks` ferry for signed thinking blocks.
3. Convert the gateway:
   - implement `SingleTurnTextGateway`
   - add `use DelegatesToTextGenerationLoop`
   - drop `use InvokesTools` from the gateway (loop owns tools now)
   - write `generateSingleTurn(...)` and `streamSingleTurn(...)`
4. Strip recursive/in-gateway tool-loop code from the provider's traits.
5. Make the streaming path yield `SingleTurnStreamEnd` at the end of one
   provider turn instead of a public `StreamEnd`.
6. Verify request and response behavior did not change.
7. Use the resulting diff to estimate the cost of the next provider.

## Measuring One Provider

Review the migration by provider, not by the whole branch:

```bash
git diff --stat 0.x...HEAD -- src/Gateway/OpenAi src/Gateway/AzureOpenAi
git diff 0.x...HEAD -- src/Gateway/OpenAi
```

For each provider, separate the diff into:

- request-building changes
- response parsing changes
- streaming parser changes
- deleted loop/tool-execution code
- the (now trivial) `DelegatesToTextGenerationLoop` opt-in

For OpenAI/Azure, the useful measurement is not total lines changed. The
important number is how much recursive loop code disappears from
`ParsesTextResponses` and `HandlesTextStreaming` while request mapping and
response parsing stay provider-owned.

## Provider Conversion Checklist

For each provider:

- Parse a single provider response into `SingleTurnResponse`.
- Convert streaming to yield exactly one provider turn and end with a
  `SingleTurnStreamEnd` — **not** a `StreamEnd`. The loop emits the public
  `StreamEnd`.
- Move local tool execution out of the gateway (drop `use InvokesTools`).
- Move max-step logic out of the gateway.
- Move final `TextResponse` / `StructuredTextResponse` assembly out of the
  gateway.
- Preserve provider-specific replay data through `SingleTurnResponse` /
  `SingleTurnStreamEnd`, for example:
  - OpenAI/Azure/xAI response IDs for stateful continuation.
  - Anthropic signed thinking or server tool-use blocks.
  - Bedrock reasoning/content blocks.
- Keep provider request-building code local to the gateway.
- Adopt `use DelegatesToTextGenerationLoop` for the multi-step entry points.

## OpenAI / Azure Invariant Tests

At minimum, these must stay green for OpenAI and Azure OpenAI:

- normal text request mapping
- structured output parsing
- tool-call follow-up request
- max steps (including clamped `maxSteps: 0`)
- streaming text events
- streaming tool calls
- streamed usage accumulated across multi-step turns
- provider options persisted through tool follow-ups
- `previous_response_id` on continuation requests
- streaming error path stops cleanly (no phantom `StreamEnd`)

## Open Architectural Questions

These should be settled before migrating the remaining providers:

- **Contract consolidation.** `SingleTurnTextGateway` lives next to
  `TextGateway`. The end state should be one contract. Decide whether
  `TextGateway` *becomes* single-turn (and `generateText`/`streamText` only
  live in the trait), or whether `SingleTurnTextGateway` replaces it
  entirely. Migrating every gateway against a sibling contract and then
  consolidating later means doing the work twice.
- **Loop ownership.** Today each gateway instantiates its own
  `TextGenerationLoop`. The Vercel-style end state has the runtime
  (`Manager` / provider text runtime) construct the loop and hand the gateway
  in as a single-turn adapter. That move belongs in a cleanup PR after enough
  gateways implement `SingleTurnTextGateway` to make `TextGateway` redundant.
- **`StepContext` extensibility.** When Anthropic / Bedrock continuation
  arrives, resist `array<string, mixed>`. Use a typed
  `?SingleTurnContinuation` where each provider returns and consumes its own
  concrete subtype. The orchestrator stays provider-agnostic.

## Why This Direction

This keeps the architectural benefit of `TextGenerationLoop` without pretending
that a mixed old/new runtime is the final design. The branch should make the
review question clear:

> How much code disappears from one provider when the provider only implements
> single-turn model communication?

With the foundation hardened (clean public `StreamEnd`, accumulated usage,
clamped `maxSteps`, shared `DelegatesToTextGenerationLoop` trait), the next
provider conversion is the answer to that question, not a re-derivation of it.
