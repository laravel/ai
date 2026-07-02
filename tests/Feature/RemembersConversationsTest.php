<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ParticipantAware;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('it scopes a participant-aware store to the participant when continuing the last conversation', function () {
    $participant = new class
    {
        public int $id = 7;
    };

    $store = new class implements ParticipantAware
    {
        public ?object $participant = null;

        public function forParticipant(?object $participant): static
        {
            $clone = clone $this;
            $clone->participant = $participant;

            return $clone;
        }

        public function latestConversationId(string|int $userId): ?string
        {
            return $this->participant === null ? null : 'conversation-'.$this->participant->id;
        }

        public function storeConversation(string|int|null $userId, string $title): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
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

    // The participant reached the scoped store, so it resolves that participant's own conversation...
    expect($agent->currentConversation())->toBe('conversation-7');
});

test('it leaves a plain store untouched when continuing the last conversation', function () {
    $participant = new class
    {
        public int $id = 7;
    };

    $store = new class implements ConversationStore
    {
        public function latestConversationId(string|int $userId): ?string
        {
            return 'conversation-1';
        }

        public function storeConversation(string|int|null $userId, string $title): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
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

    // A store that does not opt into participant awareness resolves by id alone, exactly as before...
    expect($agent->currentConversation())->toBe('conversation-1');
});
