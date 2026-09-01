<?php

namespace Tests\Fixtures\ConversationStores;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class InMemoryConversationStore implements ConversationStore
{
    public array $conversations = [];

    public array $messages = [];

    public function latestConversationId(string $participantType, string|int $participantId, ?string $agent = null): ?string
    {
        return collect($this->conversations)
            ->filter(fn ($conversation): bool => $conversation['participant_type'] === $participantType
                && $conversation['participant_id'] == $participantId)
            ->keys()
            ->last();
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        $id = (string) Str::uuid7();

        $this->conversations[$id] = ['participant_type' => $participantType, 'participant_id' => $participantId, 'title' => $title];

        return $id;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $prompt->prompt,
        ];

        return $id;
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $response->text,
        ];

        return $id;
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collect($this->messages)
            ->where('conversation_id', $conversationId)
            ->take($limit)
            ->values();
    }

    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        //
    }
}
