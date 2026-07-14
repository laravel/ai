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

        public function latestConversationId(string|int $participantId, ?string $participantType = null): ?string
        {
            $this->receivedType = $participantType;

            return $participantType === 'admin' ? 'conversation-admin' : null;
        }

        public function storeConversation(string|int|null $participantId, string $title, ?string $participantType = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string|int|null $participantId, AgentPrompt $prompt, ?string $participantType = null): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?string $participantType = null): string
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
        public function latestConversationId(string|int $participantId, ?string $participantType = null): ?string
        {
            return 'conversation-1';
        }

        public function storeConversation(string|int|null $participantId, string $title, ?string $participantType = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string|int|null $participantId, AgentPrompt $prompt, ?string $participantType = null): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response, ?string $participantType = null): string
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
