<?php

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;

function storeSubConversationTestConversation(string $id): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => null,
        'participant_id' => null,
        'title' => $id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<array<string, mixed>>  $toolCalls
 */
function storeSubConversationTestMessage(string $conversationId, string $role, array $toolCalls = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) str()->uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => null,
        'participant_id' => null,
        'agent' => 'Orchestrator',
        'role' => $role,
        'content' => '',
        'attachments' => '[]',
        'steps' => json_encode([['content' => '', 'tool_calls' => $toolCalls]]),
        'usage' => '[]',
        'meta' => '[]',
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('a conversation returns the sub-conversations linked from its tool calls', function (): void {
    foreach (['parent', 'sub-1', 'sub-2', 'unrelated'] as $id) {
        storeSubConversationTestConversation($id);
    }

    storeSubConversationTestMessage('parent', 'user');
    storeSubConversationTestMessage('parent', 'assistant', [
        ['id' => 'call_1', 'name' => 'ResearchAgent', 'arguments' => [], 'result' => 'one', 'sub_conversation_id' => 'sub-1'],
        ['id' => 'call_2', 'name' => 'lookup', 'arguments' => [], 'result' => 'plain tool'],
    ]);
    storeSubConversationTestMessage('parent', 'assistant', [
        ['id' => 'call_3', 'name' => 'ResearchAgent', 'arguments' => [], 'result' => 'two', 'sub_conversation_id' => 'sub-2'],
    ]);
    storeSubConversationTestMessage('unrelated', 'assistant', [
        ['id' => 'call_4', 'name' => 'ResearchAgent', 'arguments' => [], 'result' => 'other', 'sub_conversation_id' => 'parent'],
    ]);

    $ids = Conversation::find('parent')->subConversations()->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(['sub-1', 'sub-2']);
});

test('a conversation without delegations has no sub-conversations', function (): void {
    storeSubConversationTestConversation('parent');
    storeSubConversationTestMessage('parent', 'user');
    storeSubConversationTestMessage('parent', 'assistant', [
        ['id' => 'call_1', 'name' => 'lookup', 'arguments' => [], 'result' => 'plain tool'],
    ]);

    expect(Conversation::find('parent')->subConversations()->get())->toBeEmpty();
});

test('a sub-conversation shared by several delegations is returned once', function (): void {
    storeSubConversationTestConversation('parent');
    storeSubConversationTestConversation('shared');
    storeSubConversationTestMessage('parent', 'assistant', [
        ['id' => 'call_1', 'name' => 'ResearchAgent', 'arguments' => [], 'result' => 'one', 'sub_conversation_id' => 'shared'],
        ['id' => 'call_2', 'name' => 'ResearchAgent', 'arguments' => [], 'result' => 'two', 'sub_conversation_id' => 'shared'],
    ]);

    expect(Conversation::find('parent')->subConversations()->pluck('id')->all())->toBe(['shared']);
});
