<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('it forwards the participant to the store when continuing the last conversation', function () {
    $participant = new class
    {
        public int $id = 7;
    };

    $store = new class implements ConversationStore
    {
        public ?object $participant = null;

        public function latestConversationId(string|int $userId, ?object $participant = null): ?string
        {
            $this->participant = $participant;

            return 'conversation-1';
        }

        public function storeConversation(string|int|null $userId, string $title, ?object $participant = null): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, ?object $participant = null): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response, ?object $participant = null): string
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

    // The full participant is handed to the store, so a custom store may scope however it likes...
    expect($store->participant)->toBe($participant)
        ->and($agent->currentConversation())->toBe('conversation-1');
});
