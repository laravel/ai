<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

interface ConversationStore
{
    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(int $userId, string $title): string;

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, int $userId, AgentPrompt $prompt): string;

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, int $userId, AgentResponse $response): string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @return \Illuminate\Support\Collection<int, \Laravel\Ai\Messages\Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;
}
