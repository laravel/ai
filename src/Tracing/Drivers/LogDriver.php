<?php

namespace Laravel\Ai\Tracing\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\TracingDriver;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;

class LogDriver implements TracingDriver
{
    /**
     * Create a new log driver instance.
     */
    public function __construct(protected array $config) {}

    /**
     * Register event listeners for the given agent invocation.
     */
    public function registerListeners(string $invocationId, Agent $agent, Dispatcher $events): void
    {
        $events->listen(PromptingAgent::class, function (PromptingAgent $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId || $event instanceof \Laravel\Ai\Events\StreamingAgent) {
                return;
            }

            $this->log('Agent prompting', [
                'invocation_id' => $event->invocationId,
                'agent' => $event->prompt->agent::class,
                'model' => $event->prompt->model,
            ]);
        });

        $events->listen(\Laravel\Ai\Events\StreamingAgent::class, function (\Laravel\Ai\Events\StreamingAgent $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $this->log('Agent streaming', [
                'invocation_id' => $event->invocationId,
                'agent' => $event->prompt->agent::class,
                'model' => $event->prompt->model,
            ]);
        });

        $events->listen(InvokingTool::class, function (InvokingTool $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $this->log('Invoking tool', [
                'invocation_id' => $event->invocationId,
                'tool_invocation_id' => $event->toolInvocationId,
                'agent' => $event->agent::class,
                'tool' => $event->tool::class,
            ]);
        });

        $events->listen(ToolInvoked::class, function (ToolInvoked $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $this->log('Tool invoked', [
                'invocation_id' => $event->invocationId,
                'tool_invocation_id' => $event->toolInvocationId,
                'agent' => $event->agent::class,
                'tool' => $event->tool::class,
            ]);
        });

        $events->listen(AgentPrompted::class, function (AgentPrompted $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId || $event instanceof AgentStreamed) {
                return;
            }

            $this->log('Agent prompted', [
                'invocation_id' => $event->invocationId,
                'agent' => $event->prompt->agent::class,
                'model' => $event->response->meta->model,
                'provider' => $event->response->meta->provider,
                'usage' => $event->response->usage->toArray(),
            ]);
        });

        $events->listen(AgentStreamed::class, function (AgentStreamed $event) use ($invocationId) {
            if ($event->invocationId !== $invocationId) {
                return;
            }

            $this->log('Agent streamed', [
                'invocation_id' => $event->invocationId,
                'agent' => $event->prompt->agent::class,
                'model' => $event->response->meta->model,
                'provider' => $event->response->meta->provider,
                'usage' => $event->response->usage->toArray(),
            ]);
        });

        $events->listen(AgentFailedOver::class, function (AgentFailedOver $event) use ($invocationId, $agent) {
            if ($event->agent !== $agent) {
                return;
            }

            $this->log('Agent failed over', [
                'invocation_id' => $invocationId,
                'agent' => $event->agent::class,
                'provider' => $event->provider::class,
                'model' => $event->model,
                'exception' => $event->exception->getMessage(),
            ]);
        });
    }

    /**
     * Write a log entry.
     */
    protected function log(string $message, array $context): void
    {
        $logger = $this->config['channel']
            ? Log::channel($this->config['channel'])
            : Log::getFacadeRoot();

        $level = $this->config['level'] ?? 'info';

        $logger->{$level}("[AI Tracing] {$message}", $context);
    }
}
