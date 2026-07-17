<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('it threads the participant type into latestConversationId when continuing the last conversation', function () {
    $participant = new class
    {
        public int $id = 7;

        public function getMorphClass(): string
        {
            return 'admin';
        }
    };

    $store = new class implements ConversationStore
    {
        public ?string $receivedType = null;

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            $this->receivedType = $participantType;

            return $participantType === 'admin' ? 'conversation-admin' : null;
        }

        public function storeConversation(string $participantType, string|int $participantId, string $title): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }
    };

    app()->instance(ConversationStore::class, $store);

    $agent = (new RememberingAssistantAgent)->continueLastConversation($participant);

    // The participant's morph type reaches the store, so it resolves that participant's own conversation...
    expect($store->receivedType)->toBe('admin')
        ->and($agent->currentConversation())->toBe('conversation-admin');
});

test('it continues the last conversation through a store that ignores the participant type', function () {
    $participant = new class
    {
        public int $id = 7;
    };

    $store = new class implements ConversationStore
    {
        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            return 'conversation-1';
        }

        public function storeConversation(string $participantType, string|int $participantId, string $title): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }
    };

    app()->instance(ConversationStore::class, $store);

    $agent = (new RememberingAssistantAgent)->continueLastConversation($participant);

    expect($agent->currentConversation())->toBe('conversation-1');
});

test('it resolves the participant id via getKey for models with custom primary keys', function () {
    $participant = new class
    {
        public function getKey(): string
        {
            return 'uuid-123';
        }

        public function getMorphClass(): string
        {
            return 'admin';
        }
    };

    $store = new class implements ConversationStore
    {
        public string|int|null $receivedId = null;

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            $this->receivedId = $participantId;

            return 'conversation-1';
        }

        public function storeConversation(string $participantType, string|int $participantId, string $title): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string $participantType, string|int $participantId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }
    };

    app()->instance(ConversationStore::class, $store);

    (new RememberingAssistantAgent)->continueLastConversation($participant);

    // The participant's real primary key reaches the store, even when it is not named "id"...
    expect($store->receivedId)->toBe('uuid-123');
});
