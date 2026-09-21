<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
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

        $pendingConversationId = $agent->currentConversation() === null
            ? (string) Str::uuid7()
            : null;

        try {
            $response = $next($prompt);
        } catch (Throwable $exception) {
            $this->rememberFailedTurn($prompt, $exception);

            throw $exception;
        }

        // A stream fails while it is being consumed, long after this pipeline returned, so it reports back here...
        if ($response instanceof StreamableAgentResponse) {
            $response->catch(fn (Throwable $exception) => $this->rememberFailedTurn($prompt, $exception, retryable: ! $response->hasYielded()));
        }

        // Surface the ID to stream protocols without treating it as an existing conversation...
        if ($pendingConversationId !== null && $response instanceof StreamableAgentResponse) {
            $response->withinConversation($pendingConversationId, $agent->conversationParticipant());
        }

        return $response->then(function (AgentResponse $completedResponse) use ($prompt, $agent, $pendingConversationId): void {
            if (! $this->shouldRemember($agent, $prompt, $completedResponse)) {
                if ($pendingConversationId !== null) {
                    $completedResponse->conversationId = null;
                    $completedResponse->conversationUser = null;
                }

                return;
            }

            $participant = $agent->conversationParticipant();
            $participantType = $participant === null ? null : Conversation::participantType($participant);
            $participantId = $participant === null ? null : Conversation::participantKey($participant);

            // Create conversation if necessary...
            if ($pendingConversationId !== null || ! $agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $participantType,
                    $participantId,
                    $this->generateTitle($prompt->prompt),
                    $pendingConversationId,
                );

                $agent->continue($conversationId, $participant);
            }

            // Record user message...
            $userMessageId = null;

            if (! $prompt->hasApprovalDecisions()) {
                $userMessageId = $this->store->storeUserMessage(
                    $agent->currentConversation(),
                    $participantType,
                    $participantId,
                    $agent::class,
                    new UserMessage($prompt->prompt, $prompt->attachments),
                );
            }

            // Record assistant message...
            $assistantMessageId = $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $prompt,
                $completedResponse,
            );

            $completedResponse->withinConversation(
                $agent->currentConversation(),
                $participant,
            )->withStoredMessages($userMessageId, $assistantMessageId);
        });
    }

    /**
     * Record the steps a run completed before it died, so the tools it already ran are not lost with it.
     */
    protected function rememberFailedTurn(AgentPrompt $prompt, Throwable $exception, bool $retryable = true): void
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // A failover retry writes the turn itself, so only the attempt the caller gives up on is recorded...
        if ($retryable && $exception instanceof FailoverableException && ! $prompt->isFinalAttempt()) {
            return;
        }

        if (! $agent->hasConversationParticipant() && $agent->currentConversation() === null) {
            return;
        }

        $steps = $agent->recordedRunContext($prompt->invocationId)?->recordedSteps() ?? [];

        $agent->recordRunContext(null);

        if ($steps === []) {
            return;
        }

        $participant = $agent->conversationParticipant();
        $participantType = $participant === null ? null : Conversation::participantType($participant);
        $participantId = $participant === null ? null : Conversation::participantKey($participant);

        if ($agent->currentConversation() === null) {
            $agent->continue($this->store->storeConversation(
                $participantType,
                $participantId,
                Str::limit($prompt->prompt, 50, preserveWords: true),
            ), $participant);
        }

        if (! $prompt->hasApprovalDecisions()) {
            $this->store->storeUserMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $agent::class,
                new UserMessage($prompt->prompt, $prompt->attachments),
            );
        }

        $this->store->storeFailedAssistantMessage(
            $agent->currentConversation(),
            $participantType,
            $participantId,
            $prompt,
            $steps,
            $exception,
        );
    }

    /**
     * Determine whether this turn should be persisted.
     *
     * @param  Agent&RemembersConversations  $agent
     */
    protected function shouldRemember(Agent $agent, AgentPrompt $prompt, AgentResponse $response): bool
    {
        return $agent->hasConversationParticipant()
            || $agent->currentConversation() !== null
            || $response->hasPendingApprovals()
            || $prompt->hasApprovalDecisions();
    }

    /**
     * Generate a title for the conversation.
     */
    protected function generateTitle(string $prompt): string
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
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
}
