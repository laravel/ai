# open-telemetry/api stays out of laravel/ai

`laravel/ai` does not depend on `open-telemetry/api`, even though that package is just interfaces and a noop implementation (zero runtime cost if no SDK is installed).

## Considered Options

- **Include `open-telemetry/api` in `laravel/ai`**: emit spans directly from `TextGenerationLoop` at the right call sites, as Vercel AI SDK does with `@opentelemetry/api`. Spans would open and close exactly when the underlying work starts and finishes, without an event-listener indirection layer.
- **Keep OTel out of core, bridge via events**: `laravel/ai` fires PSR-14 lifecycle events; `vinitkadam/laravel-ai-telemetry` subscribes and assembles spans. Each backend (`laravel-ai-phoenix`, Datadog, Honeycomb) registers its own `TracerProvider`.

## Why events, not otel/api

`open-telemetry/api` is not a PHP PSR. The PHP standard for cross-cutting observability is PSR-14 (event dispatcher), which is already what `laravel/ai` implements. Adding `open-telemetry/api` to `laravel/ai` takes a position on observability infrastructure — it says "OTel is the way" — that not every user wants the framework to take.

Taylor Otwell's consistent pattern: `laravel/framework` fires events; observability packages (Telescope, Pulse, Horizon) subscribe. None of those packages are bundled into `illuminate/*`. The framework does not depend on `psr/log` because it wants to emit traces — it depends on it because logging is a first-class framework concern. Tracing is not.

The events already carry everything a span needs: model name, token counts, tool arguments, exceptions, step number, invocation ID. Any backend — OTel, Datadog direct SDK, Prometheus counters, a custom Telescope tab — can build on these events without `laravel/ai` having an opinion.

`vinitkadam/laravel-ai-telemetry` is a community package in a personal namespace, not a first-party Laravel package. `laravel/ai` should not suggest it, and it should not be designed around it. The events exist independently of whether that bridge package ever becomes official.

## Consequences

The `otel` driver in `vinitkadam/laravel-ai-telemetry` carries the `open-telemetry/api` dependency and reconstructs span timing from event timestamps. Span start/end accuracy depends on event dispatch latency, not wall-clock instrumentation — this is an accepted trade-off for keeping the core clean.

If `open-telemetry/api` ever becomes a PHP PSR or if Laravel officially adopts it (as it did `psr/log`), this decision should be revisited.
