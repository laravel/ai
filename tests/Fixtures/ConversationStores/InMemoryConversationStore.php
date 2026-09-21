<?php

namespace Tests\Fixtures\ConversationStores;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;

class InMemoryConversationStore implements ConversationStore
{
    public array $conversations = [];

    public array $messages = [];

    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        return collect($this->conversations)
            ->filter(fn ($conversation): bool => $conversation['participant_type'] === $participantType
                && $conversation['participant_id'] == $participantId)
            ->keys()
            ->last();
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
    {
        $id ??= (string) Str::uuid7();

        $this->conversations[$id] = ['participant_type' => $participantType, 'participant_id' => $participantId, 'title' => $title];

        return $id;
    }

    public function updateConversationTitle(string $conversationId, string $title): void
    {
        $this->conversations[$conversationId]['title'] = $title;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent, UserMessage $message): string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $message->content,
        ];

        return $id;
    }

    public function startAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, string $agent): string
    {
        $id = (string) Str::uuid7();

        $this->messages[] = [
            'id' => $id,
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => '',
            'steps' => [],
            'completed' => false,
        ];

        return $id;
    }

    public function resumeAssistantMessage(string $conversationId, string $provider, array $decided): ?string
    {
        return null;
    }

    public function storeStep(string $messageId, Step $step): void
    {
        $this->messages[$this->indexOf($messageId)]['steps'][] = $step;
    }

    public function storeToolResults(string $messageId, array $toolResults): void
    {
        //
    }

    public function completeAssistantMessage(string $messageId, AgentPrompt $prompt, AgentResponse $response): void
    {
        $this->messages[$this->indexOf($messageId)] = [
            ...$this->messages[$this->indexOf($messageId)],
            'content' => $response->text,
            'completed' => true,
        ];
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $id = $this->startAssistantMessage($conversationId, $participantType, $participantId, $prompt->agent::class);

        $this->completeAssistantMessage($id, $prompt, $response);

        return $id;
    }

    public function getLatestConversationMessages(string $conversationId, int $limit, ?string $before = null): Collection
    {
        return collect($this->messages)
            ->where('conversation_id', $conversationId)
            ->when($before !== null, fn (Collection $messages) => $messages->takeUntil(fn (array $message): bool => $message['id'] === $before))
            ->take(-$limit)
            ->values();
    }

    protected function indexOf(string $messageId): int
    {
        return collect($this->messages)->search(fn (array $message): bool => $message['id'] === $messageId);
    }
}
