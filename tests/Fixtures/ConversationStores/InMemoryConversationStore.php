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

    public function latestConversationId(string|int $userId): ?string
    {
        return collect($this->conversations)
            ->filter(fn ($conversation) => $conversation['user_id'] == $userId)
            ->keys()
            ->last();
    }

    public function storeConversation(string|int|null $userId, string $title): string
    {
        $id = (string) Str::uuid7();

        $this->conversations[$id] = ['user_id' => $userId, 'title' => $title];

        return $id;
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
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

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
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

    public function storeToolResults(string $conversationId, array $toolResults): void
    {
        $index = collect($this->messages)
            ->keys()
            ->last(fn ($key) => $this->messages[$key]['conversation_id'] === $conversationId
                && $this->messages[$key]['role'] === 'assistant');

        if ($index !== null) {
            $this->messages[$index]['tool_results'] = $toolResults;
        }
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collect($this->messages)
            ->where('conversation_id', $conversationId)
            ->take($limit)
            ->values();
    }
}
