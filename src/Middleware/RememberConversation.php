<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
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
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        return $next($prompt)->then(function (AgentResponse $response) use ($prompt): void {
            /** @var Agent&RemembersConversations $agent */
            $agent = $prompt->agent;

            if (! $this->shouldRemember($agent, $prompt, $response)) {
                return;
            }

            $participant = $agent->conversationParticipant();
            $participantType = $participant === null ? null : Conversation::participantType($participant);
            $participantId = $participant === null ? null : Conversation::participantKey($participant);

            // Create conversation if necessary...
            if (! $agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $participantType,
                    $participantId,
                    $this->generateTitle($prompt->prompt),
                );

                $agent->continue($conversationId, $participant);
            }

            // Record user message...
            if (! $prompt->hasApprovalDecisions()) {
                $this->store->storeUserMessage(
                    $agent->currentConversation(),
                    $participantType,
                    $participantId,
                    $prompt,
                );
            }

            // Record assistant message...
            $assistantMessageId = $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $participantType,
                $participantId,
                $prompt,
                $response,
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $participant,
                $assistantMessageId,
            );
        });
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
