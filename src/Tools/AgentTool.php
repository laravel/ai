<?php

namespace Laravel\Ai\Tools;

use Closure;
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
     * The delegation whose sub-agent is running right now, set only while it runs, like ParentInvocation.
     *
     * @var array{string, Agent, Agent&RemembersConversations}|null
     */
    protected static ?array $delegation = null;

    /**
     * The conversation each delegation was remembered in, keyed by the parent's tool call ID, until the loop collects it.
     *
     * @var array<string, string>
     */
    protected array $subConversations = [];

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
            return $this->within($delegation, fn (): string => $this->agent->prompt((string) $request['task'])->text);
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
            $stream = $this->within($delegation, fn () => $this->agent->stream((string) $request['task']));

            /** @var Generator<int, StreamEvent> $events */
            $events = $stream->getIterator();

            // Advanced by hand so every resumption of the sub-agent, not only the first, runs within its delegation...
            while ($this->within($delegation, fn (): bool => $events->valid())) {
                yield $events->current();

                $this->within($delegation, fn () => $events->next());
            }

            return (string) $stream->text;
        } finally {
            $this->endDelegation($request, $delegation);
        }
    }

    /**
     * Give the delegation a conversation of its own, held apart from the sub-agent and starting from what the app gave it.
     *
     * @return array{string, Agent, Agent&RemembersConversations}|null
     */
    protected function startDelegation(Request $request): ?array
    {
        if ($request->toolInvocationId() === null) {
            return null;
        }

        $conversation = new class('', [], []) extends AnonymousAgent implements RemembersConversations
        {
            use RemembersConversationsTrait;

            public bool $remembered = false;

            public function hasConversationParticipant(): bool
            {
                return $this->remembered;
            }
        };

        /** @var Agent&RemembersConversations $agent */
        $agent = $this->agent;

        [$conversationId, $participant, $remembered] = RememberConversation::appliesTo($agent)
            ? [$agent->currentConversation(), $agent->conversationParticipant(), $agent->hasConversationParticipant()]
            : [null, null, false];

        match (true) {
            $conversationId !== null => $conversation->continue($conversationId, $participant),
            $participant !== null => $conversation->forParticipant($participant),
            default => null,
        };

        // Given nothing, the delegation is remembered, with no participant, when its parent run is...
        $conversation->remembered = $remembered || RememberConversation::remembers(ParentInvocation::current()[0]);

        return [$request->toolInvocationId(), $this->agent, $conversation];
    }

    /**
     * Run the given callback within the given delegation.
     */
    protected function within(?array $delegation, Closure $callback): mixed
    {
        $previous = static::$delegation;

        static::$delegation = $delegation;

        try {
            return $callback();
        } finally {
            static::$delegation = $previous;
        }
    }

    /**
     * Note the conversation the delegation was remembered in, for the parent's tool call.
     */
    protected function endDelegation(Request $request, ?array $delegation): void
    {
        $conversationId = $delegation === null ? null : $delegation[2]->currentConversation();

        if ($conversationId !== null && $request->toolCallId() !== null) {
            $this->subConversations[$request->toolCallId()] = $conversationId;
        }
    }

    /**
     * Get the conversation the given sub-agent's running delegation from the given tool invocation is remembered in.
     *
     * @return (Agent&RemembersConversations)|null
     *
     * @internal
     */
    public static function delegation(Agent $agent, ?string $toolInvocationId): ?Agent
    {
        [$id, $delegated, $conversation] = static::$delegation ?? [null, null, null];

        return $id === $toolInvocationId && $delegated === $agent ? $conversation : null;
    }

    /**
     * Collect the conversation the given tool call's delegation was remembered in.
     *
     * @internal
     */
    public function pullSubConversation(string $toolCallId): ?string
    {
        $conversationId = $this->subConversations[$toolCallId] ?? null;

        unset($this->subConversations[$toolCallId]);

        return $conversationId;
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
