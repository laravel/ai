# Telemetry assembled by listening to existing domain events

The SDK already dispatches 27+ lifecycle events (`PromptingAgent`, `AgentPrompted`, `InvokingTool`, `ToolInvoked`, `GeneratingEmbeddings`, `EmbeddingsGenerated`, etc.) carrying rich domain objects with all the data needed for spans. Rather than adding a separate telemetry instrumentation layer (as Prism does with `TextGenerationStarted`/`TextGenerationCompleted` events carrying span IDs), we build telemetry by subscribing to these existing events and assembling spans from them. A `TelemetryListener` correlates start/end event pairs by `invocationId` and threads parent-child span relationships (Invocation → Step → Tool) via Laravel's hidden Context API.

## Considered Options

- **Separate telemetry event layer** (Prism's approach): instrument execution points with dedicated span-aware events that carry `spanId`/`traceId`/`parentSpanId`. Rejected because the SDK already has equivalent start/end event pairs — duplicating them would mean maintaining two parallel event systems.
- **Direct OpenTelemetry instrumentation**: call the OTel SDK directly at execution points, bypassing events entirely. Rejected because it couples the core SDK to the OTel PHP SDK and removes the ability for users to listen to telemetry events directly.

## Consequences

Two new event pairs (`StepStarted`/`StepCompleted` and `AgentFailed`/`StepFailed`) must be added to `TextGenerationLoop` because the existing events do not cover per-step LLM call boundaries or failure cases. These are the only additions required; all other event hooks already exist.
