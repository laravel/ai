<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Migrations\BackfillConversationSteps;

beforeEach(function (): void {
    Schema::drop('agent_conversation_messages');

    Schema::create('agent_conversation_messages', function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->string('participant_type')->nullable();
        $table->unsignedBigInteger('participant_id')->nullable();
        $table->string('agent');
        $table->string('role', 25);
        $table->text('content');
        $table->text('attachments');
        $table->text('tool_calls');
        $table->text('tool_results');
        $table->text('usage');
        $table->text('meta');
        $table->text('approval_state')->nullable();
        $table->timestamps();
    });
});

test('it moves a result recorded on a later row onto the step that made the call and drops the old columns', function (): void {
    insertLegacyRow('message-1', 'user', 'Delete a');
    insertLegacyRow('message-2', 'assistant', '', toolCalls: [legacyCall('call-1')], approvalState: ['pending' => []], meta: ['provider' => 'anthropic', 'provider_content_blocks' => [['type' => 'tool_use', 'id' => 'call-1']]]);
    insertLegacyRow('message-3', 'assistant', 'Deleted a.', toolResults: [legacyResult('call-1')]);

    (new BackfillConversationSteps)->up();

    $rows = DB::table('agent_conversation_messages')->orderBy('id')->get()->keyBy('id');

    expect(Schema::hasColumns('agent_conversation_messages', ['tool_calls', 'tool_results']))->toBeFalse()
        ->and($rows['message-1']->steps)->toBe('[]')
        ->and($rows['message-2']->steps)->json()->toHaveCount(1)->{'0'}->toMatchArray([
            'tool_calls' => [answeredToolCall('call-1')],
            'replay_blocks' => [['type' => 'tool_use', 'id' => 'call-1']],
        ])
        ->and($rows['message-2']->meta)->json()->toBe(['provider' => 'anthropic'])
        ->and($rows['message-3']->steps)->json()->toHaveCount(1)->{'0'}->toMatchArray(['content' => 'Deleted a.', 'tool_calls' => []]);

    $messages = (new DatabaseConversationStore)->getLatestConversationMessages('conversation-1', 10);

    expect($messages->map(fn ($message) => $message::class)->all())->toBe([
        Message::class,
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ]);
});

test('it keeps answered and pending calls and drops the call that never ran', function (): void {
    insertLegacyRow('message-1', 'assistant', 'Waiting.', toolCalls: [legacyCall('call-1'), legacyCall('call-2'), legacyCall('call-3')], toolResults: [legacyResult('call-1')], approvalState: ['pending' => ['call-2' => 'Destructive.']]);

    (new BackfillConversationSteps)->up();

    $steps = DB::table('agent_conversation_messages')->value('steps');

    expect($steps)->json()->{'0'}->tool_calls->toBe([answeredToolCall('call-1'), legacyCall('call-2')]);
});

test('it splits a row per provider step and moves the turn reasoning blob onto the last', function (): void {
    insertLegacyRow('message-1', 'assistant', 'Now b.', toolCalls: [legacyCall('call-1'), legacyCall('call-2')], toolResults: [legacyResult('call-1')], approvalState: ['pending' => ['call-2' => null]], meta: [
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'reasoning' => 'Deleting b next.',
        'provider_steps' => [
            ['blocks' => [['type' => 'thinking', 'signature' => 'sig-1']], 'tool_call_ids' => ['call-1']],
            ['blocks' => [['type' => 'thinking', 'signature' => 'sig-2']], 'tool_call_ids' => ['call-2']],
        ],
    ]);

    (new BackfillConversationSteps)->up();

    $row = DB::table('agent_conversation_messages')->first();

    expect($row->steps)->json()->toBe([
        ['content' => '', 'tool_calls' => [answeredToolCall('call-1')], 'reasoning' => '', 'replay_blocks' => [['type' => 'thinking', 'signature' => 'sig-1']]],
        ['content' => 'Now b.', 'tool_calls' => [legacyCall('call-2')], 'reasoning' => 'Deleting b next.', 'replay_blocks' => [['type' => 'thinking', 'signature' => 'sig-2']]],
    ])->and($row->meta)->json()->toBe(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
});

test('it replays a completed turn answer after the results it was written from', function (): void {
    insertLegacyRow('message-1', 'user', 'Delete a');
    insertLegacyRow('message-2', 'assistant', 'Done.', toolCalls: [legacyCall('call-1')], toolResults: [legacyResult('call-1')]);

    (new BackfillConversationSteps)->up();

    expect(DB::table('agent_conversation_messages')->where('id', 'message-2')->value('steps'))->json()->toBe([
        ['content' => '', 'tool_calls' => [answeredToolCall('call-1')], 'reasoning' => '', 'replay_blocks' => []],
        ['content' => 'Done.', 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => []],
    ]);

    $messages = (new DatabaseConversationStore)->getLatestConversationMessages('conversation-1', 10);

    expect($messages->map(fn ($message) => $message::class)->all())->toBe([
        Message::class,
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ])->and($messages->last()->content)->toBe('Done.');
});

test('it records a result duplicated across rows once, on the row that made the call', function (): void {
    insertLegacyRow('message-1', 'assistant', '', toolCalls: [legacyCall('call-1')], toolResults: [legacyResult('call-1')], approvalState: ['pending' => []]);
    insertLegacyRow('message-2', 'assistant', 'Done.', toolResults: [legacyResult('call-1')]);

    (new BackfillConversationSteps)->up();

    $rows = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($rows[0]->steps)->json()->{'0'}->tool_calls->toBe([answeredToolCall('call-1')])
        ->and($rows[1]->steps)->json()->{'0'}->toMatchArray(['tool_calls' => []]);
});

/** @return array<string, mixed> */
function legacyCall(string $id): array
{
    return ['id' => $id, 'name' => 'delete_file', 'arguments' => ['path' => 'a']];
}

/** @return array<string, mixed> */
function legacyResult(string $id): array
{
    return ['id' => $id, 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a'];
}

/** @return array<string, mixed> */
function answeredToolCall(string $id): array
{
    return [...legacyCall($id), 'result' => 'Deleted a'];
}

function insertLegacyRow(string $id, string $role, string $content, array $toolCalls = [], array $toolResults = [], ?array $approvalState = null, array $meta = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => 'conversation-1',
        'agent' => 'App\\Agents\\Assistant',
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => json_encode($toolCalls),
        'tool_results' => json_encode($toolResults),
        'usage' => '[]',
        'meta' => json_encode($meta),
        'approval_state' => $approvalState === null ? null : json_encode($approvalState),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
