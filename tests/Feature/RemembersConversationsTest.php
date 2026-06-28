<?php

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('it isolates the latest conversation by participant type when scoping is enabled', function () {
    Config::set('ai.conversations.scope_by_participant_type', true);

    $store = new DatabaseConversationStore;

    $user = new class
    {
        public int $id = 1;
    };

    $admin = new class
    {
        public int $id = 1;
    };

    $userConversation = $store->storeConversation($user->id, 'User chat', $user::class);
    $adminConversation = $store->storeConversation($admin->id, 'Admin chat', $admin::class);

    // Despite sharing id 1, each participant only resumes its own conversation...
    expect((new RememberingAssistantAgent)->continueLastConversation($admin)->currentConversation())
        ->toBe($adminConversation)
        ->and((new RememberingAssistantAgent)->continueLastConversation($user)->currentConversation())
        ->toBe($userConversation);
});

test('it does not scope by participant type when scoping is disabled', function () {
    Config::set('ai.conversations.scope_by_participant_type', false);

    $store = new DatabaseConversationStore;

    $user = new class
    {
        public int $id = 1;
    };

    // Stored under a different type than the participant's class...
    $conversationId = $store->storeConversation($user->id, 'Chat', 'App\\Models\\Admin');

    // With scoping off, the participant type is ignored and the conversation still resolves...
    expect((new RememberingAssistantAgent)->continueLastConversation($user)->currentConversation())
        ->toBe($conversationId);
});

test('it returns no participant type when scoping is disabled', function () {
    Config::set('ai.conversations.scope_by_participant_type', false);

    $participant = new class
    {
        public int $id = 1;
    };

    expect((new RememberingAssistantAgent)->forUser($participant)->conversationParticipantType())->toBeNull();
});

test('it derives the participant morph type via getMorphClass when available', function () {
    Config::set('ai.conversations.scope_by_participant_type', true);

    $participant = new class
    {
        public int $id = 5;

        public function getMorphClass(): string
        {
            return 'admin';
        }
    };

    expect((new RememberingAssistantAgent)->forUser($participant)->conversationParticipantType())->toBe('admin');
});
