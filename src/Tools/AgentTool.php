<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Stringable;

class AgentTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * The sub-agent of each running delegation, keyed by the tool invocation it runs under, with the conversation it created and the sub-run that claimed it.
     *
     * @var array<string, array{0: Agent, 1: ?string, 2?: string}>
     */
    protected static array $delegations = [];

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
        $this->startDelegation($request);

        try {
            return $this->agent->prompt((string) $request['task'])->text;
        } finally {
            $this->endDelegation($request);
        }
    }

    /**
     * Execute the sub-agent and stream its activity.
     *
     * @return Generator<int, StreamEvent, mixed, string>
     */
    public function stream(Request $request): Generator
    {
        if ($this->agent instanceof HasStructuredOutput) {
            return $this->handle($request);
        }

        $this->startDelegation($request);

        try {
            $stream = $this->agent->stream((string) $request['task']);

            yield from $stream;

            return (string) $stream->text;
        } finally {
            $this->endDelegation($request);
        }
    }

    /**
     * Note the running delegation, keyed by the tool invocation it runs under.
     */
    protected function startDelegation(Request $request): void
    {
        if ($request->toolInvocationId() !== null) {
            static::$delegations[$request->toolInvocationId()] = [$this->agent, null];
        }
    }

    /**
     * End the delegation, noting the conversation it created on the parent run, for the tool call that ran it.
     */
    protected function endDelegation(Request $request): void
    {
        $conversationId = static::$delegations[(string) $request->toolInvocationId()][1] ?? null;

        unset(static::$delegations[(string) $request->toolInvocationId()]);

        if ($request->toolCallId() !== null && ParentInvocation::current()[1] === $request->toolInvocationId()) {
            ParentInvocation::context()?->linkSubConversation($request->toolCallId(), $conversationId);
        }
    }

    /**
     * Determine whether the given run is the delegation of the given agent from the given tool invocation, claimed by the first sub-run.
     *
     * @internal
     */
    public static function delegates(Agent $agent, ?string $toolInvocationId, ?string $invocationId): bool
    {
        $key = (string) $toolInvocationId;

        return (static::$delegations[$key][0] ?? null) === $agent
            && (static::$delegations[$key][2] ??= $invocationId) === $invocationId;
    }

    /**
     * Note the conversation the delegation from the given tool invocation created.
     *
     * @internal
     */
    public static function delegatedTo(string $toolInvocationId, string $conversationId): void
    {
        if (isset(static::$delegations[$toolInvocationId])) {
            static::$delegations[$toolInvocationId][1] = $conversationId;
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

    /**
     * Determine whether the tool needs approval for the given request.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
