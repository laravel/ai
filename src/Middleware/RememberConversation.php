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
        return $next($prompt)->then(function ($response) use ($prompt) {
            /** @var Agent&RemembersConversations $agent */
            $agent = $prompt->agent;

            $participant = $agent->conversationParticipant();
            $participantType = Conversation::participantType($participant);

            // Create conversation if necessary...
            if (! $agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $participant?->id,
                    $this->generateTitle($prompt->prompt),
                    $participantType,
                );

                $agent->continue($conversationId, $participant);
            }

            // Record user message...
            $this->store->storeUserMessage(
                $agent->currentConversation(),
                $participant?->id,
                $prompt,
                $participantType,
            );

            // Record assistant message...
            $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $participant?->id,
                $prompt,
                $response,
                $participantType,
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $participant,
            );
        });
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
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            return Str::limit($response->text, 100);
        } catch (Throwable) {
            return Str::limit($prompt, 100, preserveWords: true);
        }
    }
}
