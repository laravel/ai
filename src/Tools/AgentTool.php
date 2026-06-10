<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\StreamableTool;
use Laravel\Ai\Contracts\StreamsActivity;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Stringable;
use Throwable;

class AgentTool implements StreamableTool
{
    public function __construct(protected Agent $agent)
    {
        //
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->name()
            : class_basename($this->agent);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->agent instanceof CanActAsTool
            ? $this->agent->description()
            : sprintf(
                'Delegates a task to the %s sub-agent and returns its response. Pass a clear, self-contained task description as the sub-agent runs in isolation and has no access to the parent conversation history.',
                $this->name(),
            );
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        try {
            return $this->agent->prompt((string) $request['task'])->text;
        } catch (Throwable $e) {
            return 'Agent failed: '.$e->getMessage();
        }
    }

    /**
     * Execute the tool while streaming opt-in sub-agent activity.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    public function streamHandle(Request $request): Generator
    {
        try {
            if (! $this->agent instanceof StreamsActivity) {
                return $this->handle($request);
            }

            $response = $this->agent->stream((string) $request['task']);

            foreach ($response as $event) {
                yield $event;
            }

            return (string) $response->text;
        } catch (Throwable $e) {
            return 'Agent failed: '.$e->getMessage();
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task to delegate to this agent.')->required(),
        ];
    }

    /**
     * Get the underlying agent instance.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }
}
