# Laravel AI SDK

The official Laravel SDK for integrating AI providers. It abstracts over 15+ LLM providers behind a unified agent-based API with support for multi-step tool loops, streaming, conversations, and structured output.

## Language

**Invocation**:
A single execution of an agent in response to a `prompt()` or `stream()` call, tracked end-to-end by an `invocationId`.
_Avoid_: Request, call, run

**Step**:
One LLM API call within a multi-step agentic loop. A single invocation may produce many steps when tools are used — each step being one round-trip to the model.
_Avoid_: Iteration, turn, round

**Tool Invocation**:
A single execution of a tool triggered by an LLM's tool call within a step. Belongs causally to the step that requested it.
_Avoid_: Tool call (ambiguous — `ToolCall` is also the data object describing the model's request to call a tool)

**Telemetry Span**:
A unit of traced work that records a start time, end time, and attributes. Spans form a three-level hierarchy per invocation: Invocation → Step → Tool Invocations.
_Avoid_: Trace (refers to the full distributed trace, not a single unit)

**Telemetry Context**:
Per-invocation metadata attached to spans — user identity, session, agent name, custom tags. Agents that implement `HasMetadata` return additional key-value pairs merged into span attributes. `HasMetadata` lives in `laravel/ai`; span assembly lives in `vinitkadam/laravel-ai-telemetry`.
_Avoid_: Baggage
