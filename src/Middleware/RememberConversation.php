<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Storage\RecordedTurn;
use Throwable;

class RememberConversation
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ConversationStore $store,
        protected TextProvider $provider,
    ) {}

    /**
     * Determine whether the given agent remembers its conversations.
     */
    public static function appliesTo(Agent $agent): bool
    {
        return $agent instanceof RemembersConversations
            || in_array(RemembersConversationsTrait::class, class_uses_recursive($agent), true);
    }

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        if ($agent->hasConversationParticipant() || $agent->currentConversation() !== null) {
            return $this->rememberAsItRuns($prompt, $next);
        }

        return $this->rememberOnceItPauses($prompt, $next);
    }

    /**
     * Open the turn's rows before the run so every step lands as it happens, then close them once the run returns.
     */
    protected function rememberAsItRuns(AgentPrompt $prompt, Closure $next)
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        $turn = $this->turnFor($prompt);

        $agent->recordTurn($turn);

        try {
            $response = $next($prompt);
        } catch (Throwable $exception) {
            // A failover retry of this invocation still needs the rows, so only a terminal failure lets go of them...
            if (! $exception instanceof FailoverableException || $prompt->isFinalAttempt()) {
                $agent->recordTurn(null);
            }

            throw $exception;
        }

        return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, $turn): void {
            $this->store->completeAssistantMessage($turn->assistantMessageId, $prompt, $completedResponse);

            $agent->recordTurn(null);

            if ($turn->startedConversation && $this->shouldGenerateTitle()) {
                $this->store->updateConversationTitle($agent->currentConversation(), $this->generateTitle($prompt->prompt));
            }

            $completedResponse->withinConversation(
                $agent->currentConversation(),
                $agent->conversationParticipant(),
            )->withStoredMessages($turn->userMessageId, $turn->assistantMessageId);
        });
    }

    /**
     * Get the rows this run writes to, continuing a failover attempt's rows unless steps already landed on them.
     */
    protected function turnFor(AgentPrompt $prompt): RecordedTurn
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        $attempted = $agent->recordedTurn($prompt->invocationId);

        if ($attempted === null) {
            return $this->openTurn($prompt);
        }

        if (! $attempted->hasSteps) {
            return $attempted;
        }

        // The retry starts the turn over, so the steps a failed attempt recorded stay on their own row rather than reading as one run...
        [$participantType, $participantId] = $this->participantFor($agent);

        return new RecordedTurn(
            $prompt->invocationId,
            $this->store->startAssistantMessage($agent->currentConversation(), $participantType, $participantId, $agent::class),
            $attempted->userMessageId,
            $attempted->startedConversation,
        );
    }

    /**
     * Store the conversation, the user message and the assistant turn the run is about to write.
     */
    protected function openTurn(AgentPrompt $prompt): RecordedTurn
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        if ($prompt->hasApprovalDecisions()) {
            return new RecordedTurn($prompt->invocationId, $this->pausedRowFor($prompt));
        }

        [$participantType, $participantId] = $this->participantFor($agent);

        $startedConversation = $agent->currentConversation() === null;

        if ($startedConversation) {
            $agent->continue($this->store->storeConversation(
                $participantType,
                $participantId,
                Str::limit($prompt->prompt, 50, preserveWords: true),
            ), $agent->conversationParticipant());
        }

        $conversationId = $agent->currentConversation();

        $userMessageId = $this->store->storeUserMessage(
            $conversationId,
            $participantType,
            $participantId,
            $agent::class,
            new UserMessage($prompt->prompt, $prompt->attachments),
        );

        return new RecordedTurn(
            $prompt->invocationId,
            $this->store->startAssistantMessage($conversationId, $participantType, $participantId, $agent::class),
            $userMessageId,
            $startedConversation,
        );
    }

    /**
     * Reopen the paused row a resume continues on, before anything else is written.
     *
     * @throws ApprovalMismatchException when the participant has no paused turn to resume
     */
    protected function pausedRowFor(AgentPrompt $prompt): string
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        [$participantType, $participantId] = $this->participantFor($agent);

        $conversationId = $agent->currentConversation();

        $messageId = $conversationId === null
            ? null
            : $this->store->resumeAssistantMessage($conversationId, $prompt->provider->name(), array_keys($prompt->approvalDecisions->all()));

        if ($messageId !== null) {
            return $messageId;
        }

        // A faked run never validates its decisions, so it gets a fresh row rather than the mismatch a real resume would raise...
        if (Ai::hasFakeGatewayFor($agent::class)) {
            if ($conversationId === null) {
                $agent->continue($conversationId = $this->store->storeConversation($participantType, $participantId, ''), $agent->conversationParticipant());
            }

            return $this->store->startAssistantMessage($conversationId, $participantType, $participantId, $agent::class);
        }

        throw new ApprovalMismatchException(
            'The approval results do not match a paused conversation turn.',
            $conversationId !== null && $this->store instanceof ResolvesPendingApprovals
                ? collect($this->store->pendingApprovalsFor($conversationId))
                : collect(),
        );
    }

    /**
     * An agent with no participant and no conversation is only remembered when its turn pauses for approval, which is known once the run returns.
     */
    protected function rememberOnceItPauses(AgentPrompt $prompt, Closure $next)
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        $pendingConversationId = (string) Str::uuid7();

        $response = $next($prompt);

        // Surface the ID to stream protocols without treating it as an existing conversation...
        if ($response instanceof StreamableAgentResponse) {
            $response->withinConversation($pendingConversationId, null);
        }

        return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, $pendingConversationId): void {
            if (! $completedResponse->hasPendingApprovals() && ! $prompt->hasApprovalDecisions()) {
                $completedResponse->conversationId = null;
                $completedResponse->conversationUser = null;

                return;
            }

            [$participantType, $participantId] = $this->participantFor($agent);

            $agent->continue($this->store->storeConversation(
                $participantType,
                $participantId,
                $this->generateTitle($prompt->prompt),
                $pendingConversationId,
            ), null);

            $userMessageId = $prompt->hasApprovalDecisions() ? null : $this->store->storeUserMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $agent::class,
                new UserMessage($prompt->prompt, $prompt->attachments),
            );

            $assistantMessageId = $this->store->startAssistantMessage($agent->currentConversation(), $participantType, $participantId, $agent::class);

            $this->store->completeAssistantMessage($assistantMessageId, $prompt, $completedResponse);

            $completedResponse->withinConversation($agent->currentConversation(), null)
                ->withStoredMessages($userMessageId, $assistantMessageId);
        });
    }

    /**
     * Get the agent's conversation participant as its stored type and key.
     *
     * @param  Agent&RemembersConversations  $agent
     * @return array{?string, string|int|null}
     */
    protected function participantFor(Agent $agent): array
    {
        $participant = $agent->conversationParticipant();

        return $participant === null
            ? [null, null]
            : [Conversation::participantType($participant), Conversation::participantKey($participant)];
    }

    /**
     * Generate a title for the conversation.
     */
    protected function generateTitle(string $prompt): string
    {
        if (! $this->shouldGenerateTitle()) {
            return Str::limit($prompt, 50, preserveWords: true);
        }

        try {
            $response = $this->provider->textGenerationLoop()->generate(
                $this->provider,
                $this->provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            return Str::limit($response->text, 100);
        } catch (Throwable) {
            return Str::limit($prompt, 100, preserveWords: true);
        }
    }

    /**
     * Determine whether conversation titles should be generated by a model.
     */
    protected function shouldGenerateTitle(): bool
    {
        return (bool) config('ai.conversations.generate_title', true);
    }
}
