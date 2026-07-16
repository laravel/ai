<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('it writes conversations to the default tables', function (): void {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->where('title', 'Hello')->exists())->toBeTrue();
});

test('it writes to overridden table names from config', function (): void {
    Config::set('ai.conversations.tables.conversations', 'custom_conversations');
    Config::set('ai.conversations.tables.messages', 'custom_conversation_messages');

    createConversationSchema();

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('custom_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it routes queries through the configured connection', function (): void {
    Config::set('database.connections.secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    createConversationSchema('secondary');

    $store = new DatabaseConversationStore('secondary');
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::connection('secondary')->table('agent_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it persists tool calls and results from a remembered agent prompt', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'id' => 'call_123',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
            Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'The number is 72019']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                'modelVersion' => 'gemini-3.5-flash',
            ]),
        ]),
    ]);

    $user = (object) ['id' => 1];
    $conversationId = (new DatabaseConversationStore)->storeConversation($user->id, 'Tool conversation');

    (new RememberingToolUsingAgent)
        ->continue($conversationId, $user)
        ->prompt('Generate a random number', provider: 'gemini');

    $record = DB::table('agent_conversation_messages')
        ->where('role', 'assistant')
        ->first();

    expect(json_decode((string) $record->tool_calls, true))->toBeList()
        ->and(json_decode((string) $record->tool_results, true))->toBeList();
});

test('it stores sparse keyed tool calls and results as JSON arrays', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Check my order status.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = new AgentResponse('invocation-id', 'The order has shipped.', new Usage, new Meta);
    $response->toolCalls = collect([
        2 => new ToolCall('call-1', 'lookup_order', ['id' => 1]),
        8 => new ToolCall('call-2', 'lookup_carrier', ['id' => 1]),
    ]);
    $response->toolResults = collect([
        2 => new ToolResult('call-1', 'lookup_order', ['id' => 1], ['status' => 'shipped']),
        8 => new ToolResult('call-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS']),
    ]);

    $store->storeAssistantMessage($conversationId, 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')
        ->where('role', 'assistant')
        ->first();

    expect(array_is_list(json_decode((string) $record->tool_calls, true)))->toBeTrue()
        ->and(array_is_list(json_decode((string) $record->tool_results, true)))->toBeTrue();
});

test('a bare rejection resume does not persist a blank assistant row', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Approval conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'paused-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => []]]),
        'tool_results' => json_encode([['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => [], 'result' => 'The user rejected this tool call.', 'result_id' => null]]),
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        '',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
        resume: ['call-1' => Decision::reject()],
    );

    $response = new AgentResponse('invocation-id', '', new Usage, new Meta);
    $response->toolResults = collect([
        new ToolResult('call-1', 'DeleteFile', [], 'The user rejected this tool call.'),
    ]);

    $messageId = $store->storeAssistantMessage($conversationId, 1, $prompt, $response);

    expect($messageId)->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('role', 'assistant')->count())->toBe(1);
});

test('it reloads legacy sparse keyed tool calls and results as lists', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            2 => ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]],
            8 => ['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1]],
        ]),
        'tool_results' => json_encode([
            2 => ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
            8 => ['id' => 'call-2', 'name' => 'lookup_carrier', 'arguments' => ['id' => 1], 'result' => ['carrier' => 'UPS']],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->keys()->all())->toBe([0, 1])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->keys()->all())->toBe([0, 1])
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('The order has shipped.');
});

test('it replays stored tool conversations before the final assistant response', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped'], 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('')
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls[0]->resultId)->toBe('result-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults)->toHaveCount(1)
        ->and($messages[1]->toolResults[0]->resultId)->toBe('result-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('The order has shipped.')
        ->and($messages[2]->toolCalls)->toBeEmpty();
});

test('it drops unresolved tool calls on an unmarked legacy row keeping the final assistant text', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'The order has shipped.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('The order has shipped.')
        ->and($messages[0]->toolCalls)->toBeEmpty();
});

test('it drops unresolved tool calls on an unmarked legacy row with no final text', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result_id' => 'result-1'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toBeEmpty();
});

test('it replays a resumed approval so the paused tool_use is answered', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-1' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted x',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result' => 'Deleted x', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls[0]->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Deleted x');
});

test('storing approval results for a conversation with no paused row throws', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    $store->storeApprovalResults($conversationId, 1, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);
})->throws(ApprovalMismatchException::class, 'The approval results do not match a paused conversation turn.');

test('resolving approval results keeps the pause marker with the outcomes on the tool results', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y'], 'result_id' => 'result-2'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-1' => null, 'call-2' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store->storeApprovalResults($conversationId, 1, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
        new ToolResult('call-2', 'delete_file', ['path' => 'y'], 'The user rejected this tool call.', denied: true),
    ]);

    $row = DB::table('agent_conversation_messages')->where('id', 'message-1')->first();
    $results = collect(json_decode($row->tool_results, true));

    expect(json_decode($row->approval_state, true))->toBe(['pending' => []])
        ->and($results->firstWhere('id', 'call-1'))->not->toHaveKey('denied')
        ->and($results->firstWhere('id', 'call-2')['denied'])->toBeTrue();
});

test('a partial resolve keeps the remaining pending calls on the marker', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'y'], 'result_id' => 'result-2'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-1' => 'Deletes x', 'call-2' => 'Deletes y']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $store->storeApprovalResults($conversationId, 1, [
        new ToolResult('call-1', 'delete_file', ['path' => 'x'], 'Deleted x'),
    ]);

    $state = json_decode(DB::table('agent_conversation_messages')->where('id', 'message-1')->value('approval_state'), true);

    expect($state)->toBe(['pending' => ['call-2' => 'Deletes y']]);
});

test('it keeps a tool call answered on a later row even after its paused row cleared the pending marker', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result_id' => 'result-1'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => []]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted x',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'x'], 'result' => 'Deleted x', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls[0]->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Deleted x');
});

test('it splits a mid-run pause row so an executed call is answered before the still-pending call', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete b too',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls[0]->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete b too')
        ->and($messages[2]->toolCalls)->toHaveCount(1)
        ->and($messages[2]->toolCalls[0]->id)->toBe('call-2');
});

test('it preserves provider content blocks when a mixed pause carries an executed and a gated call', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete b too',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => json_encode(['provider_content_blocks' => [['type' => 'thinking', 'signature' => 'sig-1']]]),
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->pluck('id')->all())->toBe(['call-1', 'call-2'])
        ->and($messages[0]->providerContentBlocks)->toBe([['type' => 'thinking', 'signature' => 'sig-1']])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->id)->toBe('call-1');
});

test('it drops a leading orphaned tool_result when the row window splits a pause from its resume', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Deleted a',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('Deleted a');
});

test('it merges a re-paused turn text into the new tool_use message rather than emitting two assistant messages', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => '',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result_id' => 'result-1'],
        ]),
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-1' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-2',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Let me delete that file',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-2', 'name' => 'delete_file', 'arguments' => ['path' => 'b'], 'result_id' => 'result-2'],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'delete_file', 'arguments' => ['path' => 'a'], 'result' => 'Deleted a', 'result_id' => 'result-1'],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'approval_state' => json_encode(['pending' => ['call-2' => null]]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls[0]->id)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->id)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Let me delete that file')
        ->and($messages[2]->toolCalls[0]->id)->toBe('call-2');
});

test('it rehydrates reasoning encrypted content on stored tool calls', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Reasoning conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Looking that up.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            [
                'id' => 'call-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'reasoning_id' => 'rs_1',
                'reasoning_summary' => [],
                'reasoning_encrypted_content' => 'enc-blob-1',
            ],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBe('rs_1')
        ->reasoningEncryptedContent->toBe('enc-blob-1');
});

test('it rehydrates legacy tool calls that predate reasoning encrypted content', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Legacy conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'assistant',
        'content' => 'Looking that up.',
        'attachments' => '[]',
        'tool_calls' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1]],
        ]),
        'tool_results' => json_encode([
            ['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['id' => 1], 'result' => ['status' => 'shipped']],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0]->toolCalls->first())
        ->reasoningId->toBeNull()
        ->reasoningSummary->toBeNull()
        ->reasoningEncryptedContent->toBeNull();
});

test('user messages with stored attachments are rehydrated as UserMessage', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
        ]),
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content)->toBe('Describe this image.')
        ->and($messages[0]->attachments)->toHaveCount(1)
        ->and($messages[0]->attachments->first())->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments->first()->url)->toBe('https://example.com/photo.jpg');
});

test('user messages with multiple attachment types are all rehydrated', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Multi-attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Analyze these files.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg', 'mime' => 'image/jpeg', 'name' => null],
            ['type' => 'stored-document', 'path' => 'docs/report.pdf', 'disk' => 'local', 'name' => 'report.pdf'],
        ]),
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->attachments)->toHaveCount(2)
        ->and($messages[0]->attachments[0])->toBeInstanceOf(RemoteImage::class)
        ->and($messages[0]->attachments[1])->toBeInstanceOf(StoredDocument::class)
        ->and($messages[0]->attachments[1]->path)->toBe('docs/report.pdf');
});

test('user messages with no attachments are returned as plain Message', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Plain conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Hello.',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages[0])->toBeInstanceOf(Message::class)
        ->and($messages[0])->not->toBeInstanceOf(UserMessage::class);
});

test('malformed stored attachment JSON fails loudly', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Malformed attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode(['type' => 'remote-image', 'url' => 'https://example.com/photo.jpg']),
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Stored conversation attachments must be a JSON array.');
});

test('malformed known stored attachments fail loudly', function (): void {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Malformed attachment conversation');

    DB::table('agent_conversation_messages')->insert([
        'id' => 'message-1',
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => ToolUsingAgent::class,
        'role' => 'user',
        'content' => 'Describe this image.',
        'attachments' => json_encode([
            ['type' => 'remote-image', 'mime' => 'image/jpeg'],
        ]),
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): Collection => $store->getLatestConversationMessages($conversationId, 10))
        ->toThrow(InvalidArgumentException::class, 'Cannot reconstruct [remote-image] attachment because [url] is missing or invalid.');
});

function createConversationSchema(?string $connection = null): void
{
    $schema = Schema::connection($connection);

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

    $schema->create($conversationsTable, function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->foreignId('user_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    $schema->create($messagesTable, function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36)->index();
        $table->foreignId('user_id')->nullable();
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
}
