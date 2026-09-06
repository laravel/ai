<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\ConversationStores\InMemoryConversationStore;

test('it threads the participant type into latestConversationId when continuing the last conversation', function () {
    $participant = new class extends Model
    {
        protected $guarded = [];

        public function getMorphClass(): string
        {
            return 'admin';
        }
    };

    $participant->id = 7;

    $store = new class implements ConversationStore
    {
        public ?string $receivedType = null;

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            $this->receivedType = $participantType;

            return $participantType === 'admin' ? 'conversation-admin' : null;
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, array $attributes = []): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            //
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

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, array $attributes = []): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            //
        }
    };

    app()->instance(ConversationStore::class, $store);

    $agent = (new RememberingAssistantAgent)->continueLastConversation($participant);

    expect($agent->currentConversation())->toBe('conversation-1');
});

test('it resolves the participant id via getKey for models with custom primary keys', function () {
    $participant = new class extends Model
    {
        protected $guarded = [];

        protected $primaryKey = 'uuid';

        protected $keyType = 'string';

        public $incrementing = false;
    };

    $participant->uuid = 'uuid-123';

    $store = new class implements ConversationStore
    {
        public string|int|null $receivedId = null;

        public function latestConversationId(string $participantType, string|int $participantId): ?string
        {
            $this->receivedId = $participantId;

            return 'conversation-1';
        }

        public function storeConversation(?string $participantType, string|int|null $participantId, string $title, array $attributes = []): string
        {
            return 'conversation-1';
        }

        public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
        {
            return 'user-1';
        }

        public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
        {
            return 'assistant-1';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }

        public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
        {
            //
        }
    };

    app()->instance(ConversationStore::class, $store);

    (new RememberingAssistantAgent)->continueLastConversation($participant);

    // The participant's real primary key reaches the store, even when it is not named "id"...
    expect($store->receivedId)->toBe('uuid-123');
});

test('it accumulates conversation attributes to persist on the next conversation', function (): void {
    $agent = (new RememberingAssistantAgent)->withConversationAttributes(['organization_id' => 1, 'source' => 'web']);

    expect($agent->conversationAttributes())->toBe(['organization_id' => 1, 'source' => 'web']);

    // Later calls merge into the attributes, with the later value winning...
    $agent->withConversationAttributes(['source' => 'dashboard']);

    expect($agent->conversationAttributes())->toBe(['organization_id' => 1, 'source' => 'dashboard']);
});

test('conversation attributes reach the store when the first prompt creates the conversation', function (): void {
    $store = new InMemoryConversationStore;

    app()->instance(ConversationStore::class, $store);

    RememberingAssistantAgent::fake(['Fake response', 'A Nice Title']);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)
        ->withConversationAttributes(['organization_id' => 5, 'source' => 'web'])
        ->forUser($user)
        ->prompt('Test prompt');

    $conversation = $store->conversations[$response->conversationId];

    expect($conversation['attributes'])->toBe(['organization_id' => 5, 'source' => 'web']);
});
