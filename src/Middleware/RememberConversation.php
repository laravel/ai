<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
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
            $agent = $prompt->agent;

            // Create conversation if necessary...
            if (! $agent->currentConversation()) {
                $conversationId = $this->store->storeConversation(
                    $agent->conversationParticipant()?->id,
                    $this->generateTitle($prompt->prompt)
                );

                $agent->continue(
                    $conversationId,
                    $agent->conversationParticipant()
                );
            }

            // Record user message...
            $this->store->storeUserMessage(
                $agent->currentConversation(),
                $agent->conversationParticipant()?->id,
                $prompt
            );

            // Record assistant message...
            $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $agent->conversationParticipant()?->id,
                $prompt,
                $response
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $agent->conversationParticipant(),
            );
        });
    }

    /**
     * Generate a title for the conversation.
     */
    protected function generateTitle(string $prompt): string
    {
        try {
            $response = $this->provider->textGateway()->generateText(
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
