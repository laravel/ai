<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

interface ConversationStore
{
    /**
     * Get the most recent conversation ID for a given participant.
     */
    public function latestConversationId(string $participantType, string|int $participantId): ?string;

    /**
     * Store a new conversation and return its ID.
     */
    public function storeConversation(string $participantType, string|int $participantId, string $title): string;

    /**
     * Store a new user message for the given conversation and return its ID.
     */
    public function storeUserMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt): string;

    /**
     * Store a new assistant message for the given conversation and return its ID.
     */
    public function storeAssistantMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt, AgentResponse $response): string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;
}
