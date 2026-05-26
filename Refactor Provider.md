# Single-Turn Gateway Refactor Direction

This note captures the intended direction after the TextGenerationLoop foundation branch.
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
- `TextGateway::generateText()` and `TextGateway::streamText()` must keep the
  same public signatures while this package still exposes them.
- Tool-loop behavior must remain the same: tool calls execute, follow-up
  requests are sent, steps and usage are accumulated, and streaming still emits
  the existing public events.
- OpenAI/Azure continuation via `previous_response_id` must continue working.

For other providers during the draft:

- It is acceptable for tests to fail until the provider is migrated.
- It is acceptable to temporarily remove duplicated gateway-owned loop code.
- The important review artifact is the provider conversion diff.

Before release, all providers must either be migrated or wrapped by an explicit
compatibility adapter. The released public API should not regress.

## Recommended Refactor Sequence

1. Keep the current TextGenerationLoop foundation.
2. Pick one provider as the reference conversion. OpenAI is already the best
   reference because it has both non-streaming and streaming tool loops, plus
   `previous_response_id` continuation.
3. Convert the provider gateway into single-turn methods:
   - `generateSingleTurn(...)`
   - `streamSingleTurn(...)`
4. Leave only thin compatibility wrappers on OpenAI/Azure:
   - `generateText(...)` delegates to `TextGenerationLoop::generate(...)`
   - `streamText(...)` delegates to `TextGenerationLoop::stream(...)`
5. Remove recursive or in-gateway tool-loop code from the provider traits.
6. Verify the request and response behavior did not change for OpenAI/Azure.
7. Use the resulting diff to estimate the cost of converting the next provider.

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
- compatibility wrapper code, if the provider must remain non-breaking

For OpenAI/Azure, the useful measurement is not only total lines changed. The
important number is how much recursive loop code disappears from
`ParsesTextResponses` and `HandlesTextStreaming` while request mapping and
response parsing stay provider-owned.

## Provider Conversion Checklist

For each provider:

- Parse a single provider response into `SingleTurnResponse`.
- Convert streaming to yield exactly one provider turn and end with `StreamEnd`.
- Move local tool execution out of the gateway.
- Move max-step logic out of the gateway.
- Move final `TextResponse` / `StructuredTextResponse` assembly out of the
  gateway.
- Preserve provider-specific replay data through `SingleTurnResponse`, for
  example:
  - OpenAI/Azure/xAI response IDs for stateful continuation.
  - Anthropic signed thinking or server tool-use blocks.
  - Bedrock reasoning/content blocks.
- Keep provider request-building code local to the gateway.

## OpenAI / Azure Invariant Tests

At minimum, these must stay green for OpenAI and Azure OpenAI:

- normal text request mapping
- structured output parsing
- tool-call follow-up request
- max steps
- streaming text events
- streaming tool calls
- provider options persisted through tool follow-ups
- `previous_response_id` on continuation requests

## Why This Direction

This keeps the architectural benefit of TextGenerationLoop without pretending that a mixed
old/new runtime is the final design. The branch should make the review question
clear:

> How much code disappears from one provider when the provider only implements
> single-turn model communication?

Once that answer is clear, the next decision is whether to migrate all providers
directly or add a short-lived adapter for unconverted providers.
