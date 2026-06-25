# Telemetry extracted to vinitkadam03/laravel-ai-telemetry

Supersedes [ADR-0002](./0002-telemetry-drivers-in-core-package.md).

All OTel instrumentation — `TelemetryListener`, `SpanCollector`, `GenAiAttributes`, `TelemetryDriver`, all drivers, `TelemetryManager` — has been moved to the separate package `vinitkadam03/laravel-ai-telemetry`. `laravel/ai` retains only the lifecycle events and the `HasTelemetryContext` interface (an agent-facing contract, not a telemetry concern).

## Why

The core SDK's job is AI inference. Observability is orthogonal — it should be opt-in, not bundled. Taylor Otwell's own pattern for observability is a separate package: `laravel/framework` fires events; Telescope, Pulse, and Horizon consume them. Bundling the OTel layer in `laravel/ai` takes a position on observability infrastructure that not every user wants.

The events already carry all the data needed. `PromptingAgent`, `AgentPrompted`, `StepStarted`, `StepCompleted`, `InvokingTool`, `ToolInvoked`, and equivalents for embeddings/image/audio/transcription/reranking form complete start/end pairs with model names, token counts, tool arguments, and exceptions. Any monitoring system — OTel, Datadog SDK directly, Prometheus counters, Telescope — can build on these events without the SDK taking a position.

The Scout/Mail/Filesystem analogy from ADR-0002 does not hold: those drivers implement framework concerns (transport, storage). Telemetry is not a framework concern.

## Consequences

`laravel/ai` has zero OTel dependency — not even in `suggest`. Its `composer.json` suggests `vinitkadam03/laravel-ai-telemetry` for users who want OTel spans.

`vinitkadam03/laravel-ai-telemetry` ships four drivers:

- **null** — discards all spans. Safe default.
- **log** — writes completed spans to a Laravel log channel. No OTel required.
- **otlp** — manages its own `TracerProvider` + `BatchSpanProcessor` + HTTP exporter. Config-driven via `AI_TELEMETRY_OTLP_ENDPOINT`. Requires `open-telemetry/sdk` and `open-telemetry/exporter-otlp`. For teams with no existing OTel infrastructure.
- **otel** — calls `Globals::tracerProvider()->getTracer('laravel-ai')` and emits into whatever `TracerProvider` the application has registered. Requires only `open-telemetry/api`. For teams already running OTel infrastructure (Datadog agent, Honeycomb collector, Jaeger, custom `SpanProcessor`). AI spans nest naturally inside existing HTTP/DB traces.

The `otel` driver enables a composable ecosystem: `vinitkadam03/laravel-ai-phoenix` is a service provider that registers a Phoenix-backed `TracerProvider` via `Globals::registerInitializer`. Users who want Phoenix support install both packages and set `AI_TELEMETRY_DRIVER=otel`. Other backends (Datadog, Honeycomb, OpenInference) follow the same pattern without changes to either core package.
