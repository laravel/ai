<?php

namespace Tests\Fixtures;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class FakeConversationStore implements ConversationStore
{
    public function latestConversationId(string|int $userId, ?object $participant = null): ?string
    {
        return null;
    }

    public function storeConversation(string|int|null $userId, string $title, ?object $participant = null): string
    {
        return 'conversation-123';
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, ?object $participant = null): string
    {
        return 'user-message-123';
    }

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response, ?object $participant = null): string
    {
        return 'assistant-message-123';
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return new Collection;
    }
}
