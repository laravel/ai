<?php

namespace Tests\Fixtures;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class FakeConversationStore implements ConversationStore
{
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return null;
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        return $id ?? 'conversation-123';
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        return 'user-message-123';
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        return 'assistant-message-123';
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return new Collection;
    }

    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        //
    }
}
