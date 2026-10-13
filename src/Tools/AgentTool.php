<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Stringable;
use Throwable;

class AgentTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * The usage of the sub-agent's latest run and the agents it delegated to, or null when that run failed.
     */
    protected ?TextUsage $usage = null;

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
        $this->usage = null;

        try {
            return $this->agent->prompt((string) $request['task'])->then($this->recordUsage(...))->text;
        } catch (Throwable $throwable) {
            // Degraded to a result so the parent run survives; the failure surfaces as the sub-agent's own AgentFailed...
            return 'Agent failed: '.$throwable->getMessage();
        }
    }

    /**
     * Execute the sub-agent and stream its activity.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    public function stream(Request $request): Generator
    {
        $this->usage = null;

        try {
            $stream = $this->agent->stream((string) $request['task'])->then($this->recordUsage(...));

            yield from $stream;

            return (string) $stream->text;
        } catch (Throwable $throwable) {
            return 'Agent failed: '.$throwable->getMessage();
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
     * Get the usage of the sub-agent's latest run and the agents it delegated to.
     */
    public function usage(): ?TextUsage
    {
        return $this->usage;
    }

    /**
     * Record the usage of the given sub-agent run, adding the usage of the agents it delegated to.
     */
    protected function recordUsage(AgentResponse $response): void
    {
        $this->usage = $response->toolResults->reduce(
            fn (TextUsage $usage, ToolResult $result): TextUsage => $result->usage ? $usage->add($result->usage) : $usage,
            $response->usage,
        );
    }

    /**
     * Get the underlying agent instance.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
