<?php

namespace Tests\Fixtures;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;

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

    public function updateConversationTitle(string $conversationId, string $title): void
    {
        //
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        return 'user-message-123';
    }

    public function startAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent): string
    {
        return 'assistant-message-123';
    }

    public function resumeAssistantMessage(string $conversationId, string $provider, array $decided): ?string
    {
        return null;
    }

    public function storeStep(string $messageId, Step $step): void
    {
        //
    }

    public function storeToolResults(string $messageId, array $toolResults): void
    {
        //
    }

    public function completeAssistantMessage(string $messageId, AgentPrompt $prompt, AgentResponse $response): void
    {
        //
    }

    public function getLatestConversationMessages(string $conversationId, int $limit, ?string $before = null): Collection
    {
        return new Collection;
    }
}
