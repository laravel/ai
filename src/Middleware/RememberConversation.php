<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;

class RememberConversation
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected ConversationStore $store) {}

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
                    $agent->conversationParticipant()->id,
                    $prompt->prompt
                );

                $agent->continue(
                    $conversationId,
                    $agent->conversationParticipant()
                );
            }

            // Record user message...
            $this->store->storeUserMessage(
                $agent->currentConversation(),
                $agent->conversationParticipant()->id,
                $prompt
            );

            // Record assistant message...
            $this->store->storeAssistantMessage(
                $agent->currentConversation(),
                $agent->conversationParticipant()->id,
                $response
            );

            $response->withinConversation(
                $agent->currentConversation(),
                $agent->conversationParticipant(),
            );
        });
    }
}
