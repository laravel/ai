<?php

namespace Laravel\Ai\Tracing;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

class LangfusePayloadBuilder
{
    /**
     * The trace ID for the current invocation.
     */
    protected string $traceId;

    /**
     * The generation span ID.
     */
    protected string $generationId;

    /**
     * The start time of the invocation.
     */
    protected string $startTime;

    /**
     * Collected tool span data keyed by tool invocation ID.
     *
     * @var array<string, array>
     */
    protected array $toolSpans = [];

    /**
     * Collected failover events.
     *
     * @var array<int, array>
     */
    protected array $failoverEvents = [];

    /**
     * Create a new payload builder instance.
     */
    public function __construct(
        protected string $invocationId,
        protected Agent $agent,
    ) {
        $this->traceId = $this->invocationId;
        $this->generationId = (string) Str::uuid7();
        $this->startTime = now()->toIso8601ZuluString();
    }

    /**
     * Record a tool invocation starting.
     */
    public function recordToolInvoking(InvokingTool $event): void
    {
        $this->toolSpans[$event->toolInvocationId] = [
            'id' => $event->toolInvocationId,
            'name' => class_basename($event->tool),
            'start_time' => now()->toIso8601ZuluString(),
            'input' => $event->arguments,
        ];
    }

    /**
     * Record a tool invocation completing.
     */
    public function recordToolInvoked(ToolInvoked $event): void
    {
        if (isset($this->toolSpans[$event->toolInvocationId])) {
            $this->toolSpans[$event->toolInvocationId]['end_time'] = now()->toIso8601ZuluString();
            $this->toolSpans[$event->toolInvocationId]['output'] = is_string($event->result)
                ? $event->result
                : json_encode($event->result);
        }
    }

    /**
     * Record a failover event.
     */
    public function recordFailover(AgentFailedOver $event): void
    {
        $this->failoverEvents[] = [
            'provider' => $event->provider::class,
            'model' => $event->model,
            'exception' => $event->exception->getMessage(),
            'time' => now()->toIso8601ZuluString(),
        ];
    }

    /**
     * Build the final Langfuse batch ingestion payload.
     */
    public function build(AgentPrompted $event): array
    {
        $endTime = now()->toIso8601ZuluString();
        $batch = [];

        // Trace
        $batch[] = [
            'id' => (string) Str::uuid7(),
            'type' => 'trace-create',
            'timestamp' => $this->startTime,
            'body' => [
                'id' => $this->traceId,
                'name' => class_basename($this->agent),
                'input' => $event->prompt->prompt,
                'output' => $event->response->text,
                'metadata' => [
                    'agent_class' => $this->agent::class,
                    'invocation_id' => $this->invocationId,
                ],
            ],
        ];

        // Generation span for the LLM call
        $batch[] = [
            'id' => (string) Str::uuid7(),
            'type' => 'generation-create',
            'timestamp' => $this->startTime,
            'body' => [
                'id' => $this->generationId,
                'traceId' => $this->traceId,
                'name' => 'llm-generation',
                'startTime' => $this->startTime,
                'endTime' => $endTime,
                'model' => $event->response->meta->model,
                'input' => $event->prompt->prompt,
                'output' => $event->response->text,
                'usage' => [
                    'promptTokens' => $event->response->usage->promptTokens,
                    'completionTokens' => $event->response->usage->completionTokens,
                ],
                'metadata' => [
                    'provider' => $event->response->meta->provider,
                ],
            ],
        ];

        // Tool spans
        foreach ($this->toolSpans as $toolSpan) {
            $batch[] = [
                'id' => (string) Str::uuid7(),
                'type' => 'span-create',
                'timestamp' => $toolSpan['start_time'],
                'body' => [
                    'id' => $toolSpan['id'],
                    'traceId' => $this->traceId,
                    'parentObservationId' => $this->generationId,
                    'name' => $toolSpan['name'],
                    'startTime' => $toolSpan['start_time'],
                    'endTime' => $toolSpan['end_time'] ?? $endTime,
                    'input' => $toolSpan['input'],
                    'output' => $toolSpan['output'] ?? null,
                ],
            ];
        }

        // Failover events
        foreach ($this->failoverEvents as $failover) {
            $batch[] = [
                'id' => (string) Str::uuid7(),
                'type' => 'event-create',
                'timestamp' => $failover['time'],
                'body' => [
                    'id' => (string) Str::uuid7(),
                    'traceId' => $this->traceId,
                    'name' => 'failover',
                    'metadata' => [
                        'provider' => $failover['provider'],
                        'model' => $failover['model'],
                        'exception' => $failover['exception'],
                    ],
                ],
            ];
        }

        return ['batch' => $batch];
    }
}
