<?php

namespace Laravel\Ai\Tracing\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\TracingDriver;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tracing\Jobs\SendLangfuseTrace;
use Laravel\Ai\Tracing\LangfusePayloadBuilder;

class LangfuseDriver implements TracingDriver
{
    /**
     * Create a new Langfuse driver instance.
     */
    public function __construct(protected array $config) {}

    /**
     * Register event listeners for the given agent invocation.
     */
    public function registerListeners(string $invocationId, Agent $agent, Dispatcher $events): void
    {
        $builder = new LangfusePayloadBuilder($invocationId, $agent);

        $events->listen(InvokingTool::class, function (InvokingTool $event) use ($invocationId, $builder) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $builder->recordToolInvoking($event);
        });

        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use ($invocationId, $builder) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $builder->recordToolInvoked($event);
        });

        $events->listen(AgentFailedOver::class, function (AgentFailedOver $event) use ($agent, $builder) {
            if ($event->agent !== $agent) {
                return;
            }

            $builder->recordFailover($event);
        });

        $events->listen(AgentPrompted::class, function (AgentPrompted $event) use ($invocationId, $builder) {
            if ($event->invocationId !== $invocationId || $event instanceof AgentStreamed) {
                return;
            }

            $this->dispatchTrace($builder->build($event));
        });

        $events->listen(AgentStreamed::class, function (AgentStreamed $event) use ($invocationId, $builder) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $this->dispatchTrace($builder->build($event));
        });
    }

    /**
     * Dispatch the trace payload as a queued job.
     */
    protected function dispatchTrace(array $payload): void
    {
        SendLangfuseTrace::dispatch(
            $payload,
            $this->config['url'],
            $this->config['public_key'],
            $this->config['secret_key'],
        )->onQueue($this->config['queue'] ?? 'default');
    }
}
