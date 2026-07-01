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

    public function latestConversationId(string|int $userId, ?object $participant = null): ?string
    {
        return collect($this->conversations)
            ->filter(fn ($conversation) => $conversation['user_id'] == $userId)
            ->keys()
            ->last();
    }

    public function storeConversation(string|int|null $userId, string $title, ?object $participant = null): string
    {
        $id = (string) Str::uuid7();

        $this->conversations[$id] = ['user_id' => $userId, 'title' => $title];

        return $id;
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, ?object $participant = null): string
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

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response, ?object $participant = null): string
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
}
