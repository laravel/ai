<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Support\Collection;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

interface ConversationStore
{
    /**
     * Get the most recent conversation ID for a given user.
     */
    public function latestConversationId(int $userId): ?string;

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
    public function storeAssistantMessage(string $conversationId, int $userId, AgentPrompt $prompt, AgentResponse $response): string;

    /**
     * Get the latest messages for the given conversation.
     *
     * @return \Illuminate\Support\Collection<int, \Laravel\Ai\Messages\Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection;

    /**
     * Set the tenant context for multi-tenant applications.
     */
    public function forTenant(int|string $tenantId): static;

    /**
     * Get the current tenant identifier.
     */
    public function currentTenant(): int|string|null;

    /**
     * Determine if a tenant context is set.
     */
    public function hasTenantContext(): bool;
}
