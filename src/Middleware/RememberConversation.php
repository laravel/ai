<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations;
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
            $this->rememberFailedTurn($prompt, $exception, $pendingConversationId);

            throw $exception;
        }

        // A stream fails while it is being consumed, long after this pipeline returned, so it reports back here...
        if ($response instanceof StreamableAgentResponse) {
            $response->catch(fn (Throwable $exception) => $this->rememberFailedTurn(
                $prompt, $exception, $pendingConversationId, retryable: ! $response->hasYielded(),
            ));
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

            $userMessageId = $this->openTurn($agent, $prompt, $pendingConversationId);

            [$participantType, $participantId] = $this->participantKeys($participant);

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
    protected function rememberFailedTurn(AgentPrompt $prompt, Throwable $exception, ?string $pendingConversationId, bool $retryable = true): void
    {
        /** @var Agent&RemembersConversations $agent */
        $agent = $prompt->agent;

        // A failover retry writes the turn itself, so only the attempt the caller gives up on is recorded...
        if ($retryable && $prompt->willRetry($exception)) {
            return;
        }

        $context = $prompt->runContext();

        $prompt->setRunContext(null);

        if ($context === null || ! $this->shouldRememberTurn($agent, $prompt)) {
            return;
        }

        $response = $context->recordedResponse();

        // A resume that died before its first step still has to fail the row its approvals were written to...
        if ($response->steps->isEmpty() && ! $prompt->hasApprovalDecisions()) {
            return;
        }

        $this->openTurn($agent, $prompt, $pendingConversationId);

        [$participantType, $participantId] = $this->participantKeys($agent->conversationParticipant());

        $this->store->storeAssistantMessage(
            $agent->currentConversation(),
            $participantType,
            $participantId,
            $prompt,
            $response,
            $exception,
        );
    }

    /**
     * Open the conversation this turn belongs to and record the prompt that started it.
     *
     * @param  Agent&RemembersConversations  $agent
     */
    protected function openTurn(Agent $agent, AgentPrompt $prompt, ?string $pendingConversationId): ?string
    {
        $participant = $agent->conversationParticipant();

        [$participantType, $participantId] = $this->participantKeys($participant);

        if ($pendingConversationId !== null || ! $agent->currentConversation()) {
            $agent->continue($this->store->storeConversation(
                $participantType,
                $participantId,
                $this->generateTitle($prompt->prompt),
                $pendingConversationId,
            ), $participant);
        }

        // A resume continues the turn its decisions answer, so it adds no message of its own...
        return $prompt->hasApprovalDecisions() ? null : $this->store->storeUserMessage(
            $agent->currentConversation(),
            $participantType,
            $participantId,
            $agent::class,
            new UserMessage($prompt->prompt, $prompt->attachments),
        );
    }

    /**
     * Split a participant into the type and key it is stored under.
     *
     * @return array{?string, string|int|null}
     */
    protected function participantKeys(?object $participant): array
    {
        return $participant === null
            ? [null, null]
            : [Conversation::participantType($participant), Conversation::participantKey($participant)];
    }

    /**
     * Determine whether this turn should be persisted.
     *
     * @param  Agent&RemembersConversations  $agent
     */
    protected function shouldRemember(Agent $agent, AgentPrompt $prompt, AgentResponse $response): bool
    {
        return $this->shouldRememberTurn($agent, $prompt)
            || $response->hasPendingApprovals();
    }

    /**
     * Determine whether this turn belongs to a conversation, however it ended.
     *
     * @param  Agent&RemembersConversations  $agent
     */
    protected function shouldRememberTurn(Agent $agent, AgentPrompt $prompt): bool
    {
        return $agent->hasConversationParticipant()
            || $agent->currentConversation() !== null
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
