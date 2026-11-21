<?php

namespace Laravel\Ai\Tools;

use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Stringable;

class AgentTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * The sub-agent and conversation of each running delegation, keyed by the tool invocation it runs under, with the sub-run that claimed it.
     *
     * @var array<string, array{0: Agent, 1: Agent&RemembersConversations, 2?: string}>
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
        $delegation = $this->startDelegation($request);

        try {
            return $this->agent->prompt((string) $request['task'])->text;
        } finally {
            $this->endDelegation($request, $delegation);
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

        $delegation = $this->startDelegation($request);

        try {
            $stream = $this->agent->stream((string) $request['task']);

            yield from $stream;

            return (string) $stream->text;
        } finally {
            $this->endDelegation($request, $delegation);
        }
    }

    /**
     * Give the delegation a conversation of its own, held apart from the sub-agent and starting from what the app gave it.
     *
     * @return (Agent&RemembersConversations)|null
     *
     * @internal
     */
    protected function startDelegation(Request $request): ?Agent
    {
        if ($request->toolInvocationId() === null) {
            return null;
        }

        $conversation = new class('', [], []) extends AnonymousAgent implements RemembersConversations
        {
            use RemembersConversationsTrait;

            /**
             * The sub-agent, read while the delegation has no participant of its own, as it may set one during its run.
             *
             * @var (Agent&RemembersConversations)|null
             */
            public ?Agent $agent = null;

            public bool $remembered = false;

            /**
             * Whether the delegation shares the conversation the sub-agent is in, read as the sub-agent runs, as it may change it.
             */
            public bool $shared = false;

            public function currentConversation(): ?string
            {
                return $this->conversationId ?? ($this->shared ? $this->agent?->currentConversation() : null);
            }

            public function hasConversationParticipant(): bool
            {
                return $this->remembered
                    || $this->conversationUser !== null
                    || $this->agent?->hasConversationParticipant() === true;
            }

            public function conversationParticipant(): ?object
            {
                return $this->conversationUser ?? $this->agent?->conversationParticipant();
            }
        };

        /** @var (Agent&RemembersConversations)|null $agent */
        $agent = RememberConversation::appliesTo($this->agent) ? $this->agent : null;

        $conversation->agent = $agent;

        $conversation->shared = $agent?->currentConversation() !== null;

        // Given nothing, the delegation is remembered, with no participant, when its parent run is...
        $remembersDelegations = ParentInvocation::context()?->remembersDelegations;

        $conversation->remembered = $remembersDelegations !== null && $remembersDelegations();

        static::$delegations[$request->toolInvocationId()] = [$this->agent, $conversation];

        return $conversation;
    }

    /**
     * End the delegation, noting the conversation it was remembered in on the parent run, for the tool call that ran it.
     *
     * @param  (Agent&RemembersConversations)|null  $conversation
     *
     * @internal
     */
    protected function endDelegation(Request $request, ?Agent $conversation): void
    {
        unset(static::$delegations[(string) $request->toolInvocationId()]);

        /** @var Agent&RemembersConversations $agent */
        $agent = $this->agent;

        $conversationId = $conversation?->currentConversation();

        // Only the run whose tool call this is collects the link, so it lives no longer than that run...
        if ($request->toolCallId() === null || ParentInvocation::current()[1] !== $request->toolInvocationId()) {
            return;
        }

        // Only a conversation the delegation created is linked; one the app gave the sub-agent is shared, and the app knows it...
        ParentInvocation::context()?->linkSubConversation(
            $request->toolCallId(),
            RememberConversation::appliesTo($agent) && $conversationId === $agent->currentConversation() ? null : $conversationId,
        );
    }

    /**
     * Get the conversation the given sub-agent's running delegation from the given tool invocation is remembered in.
     *
     * Given the sub-run's invocation ID, the first sub-run claims the delegation, so another prompt to the same sub-agent while it runs is not that delegation.
     *
     * @return (Agent&RemembersConversations)|null
     *
     * @internal
     */
    public static function delegation(Agent $agent, ?string $toolInvocationId, ?string $invocationId = null): ?Agent
    {
        $key = (string) $toolInvocationId;

        [$delegated, $conversation] = static::$delegations[$key] ?? [null, null];

        if ($delegated !== $agent) {
            return null;
        }

        if ($invocationId !== null && (static::$delegations[$key][2] ??= $invocationId) !== $invocationId) {
            return null;
        }

        return $conversation;
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
