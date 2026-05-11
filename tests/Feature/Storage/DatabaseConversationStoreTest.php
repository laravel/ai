<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('it writes conversations to the default tables', function () {
    $store = new DatabaseConversationStore;

    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('agent_conversations')->where('id', $conversationId)->where('title', 'Hello')->exists())->toBeTrue();
});

test('it writes to overridden table names from config', function () {
    Config::set('ai.conversations.tables.conversations', 'custom_conversations');
    Config::set('ai.conversations.tables.messages', 'custom_conversation_messages');

    createConversationSchema();

    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Hello');

    expect(DB::table('custom_conversations')->where('id', $conversationId)->exists())->toBeTrue()
        ->and(DB::table('agent_conversations')->where('id', $conversationId)->exists())->toBeFalse();
});

test('it routes queries through the configured connection', function () {
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

test('it persists tool calls and results from a remembered agent prompt', function () {
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
                'modelVersion' => 'gemini-3-flash-preview',
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
                'modelVersion' => 'gemini-3-flash-preview',
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

    expect(json_decode($record->tool_calls, true))->toBeList()
        ->and(json_decode($record->tool_results, true))->toBeList();
});

test('it stores sparse keyed tool calls and results as JSON arrays', function () {
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

    expect(array_is_list(json_decode($record->tool_calls, true)))->toBeTrue()
        ->and(array_is_list(json_decode($record->tool_results, true)))->toBeTrue();
});

test('it reloads persisted assistant response messages in their original order', function () {
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation(1, 'Tool conversation');

    $prompt = new AgentPrompt(
        new ToolUsingAgent,
        'Check my order status.',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );

    $response = (new AgentResponse('invocation-id', 'Both lookups are complete.', new Usage, new Meta))
        ->withMessages(collect([
            new AssistantMessage('', collect([
                new ToolCall('fc-1', 'lookup_order', ['id' => 1], 'call-1'),
            ])),
            new ToolResultMessage(collect([
                new ToolResult('fc-1', 'lookup_order', ['id' => 1], ['status' => 'shipped'], 'call-1'),
            ])),
            new AssistantMessage('', collect([
                new ToolCall('fc-2', 'lookup_carrier', ['id' => 1], 'call-2'),
            ])),
            new ToolResultMessage(collect([
                new ToolResult('fc-2', 'lookup_carrier', ['id' => 1], ['carrier' => 'UPS'], 'call-2'),
            ])),
            new AssistantMessage('Both lookups are complete.'),
        ]));

    $store->storeAssistantMessage($conversationId, 1, $prompt, $response);

    $record = DB::table('agent_conversation_messages')
        ->where('role', 'assistant')
        ->first();
    $meta = json_decode($record->meta, true);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($meta['conversation_message_sequence'][0])->toHaveKey('tool_call_keys')
        ->not->toHaveKey('tool_calls')
        ->and($messages)->toHaveCount(5)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->content)->toBe('')
        ->and($messages[0]->toolCalls[0]->resultId)->toBe('call-1')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->resultId)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->toolCalls[0]->resultId)->toBe('call-2')
        ->and($messages[3])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[3]->toolResults[0]->resultId)->toBe('call-2')
        ->and($messages[4])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[4]->content)->toBe('Both lookups are complete.');
});

test('it reloads legacy sparse keyed tool calls and results as lists', function () {
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

test('it reloads legacy reasoning tool calls before their tool results and final text', function () {
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
            [
                'id' => 'fc-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'result_id' => 'call-1',
                'reasoning_id' => 'rs-1',
                'reasoning_summary' => [['type' => 'summary_text', 'text' => 'Check the order']],
            ],
            [
                'id' => 'fc-2',
                'name' => 'lookup_carrier',
                'arguments' => ['id' => 1],
                'result_id' => 'call-2',
                'reasoning_id' => 'rs-2',
                'reasoning_summary' => [],
            ],
        ]),
        'tool_results' => json_encode([
            [
                'id' => 'fc-1',
                'name' => 'lookup_order',
                'arguments' => ['id' => 1],
                'result' => ['status' => 'shipped'],
                'result_id' => 'call-1',
            ],
            [
                'id' => 'fc-2',
                'name' => 'lookup_carrier',
                'arguments' => ['id' => 1],
                'result' => ['carrier' => 'UPS'],
                'result_id' => 'call-2',
            ],
        ]),
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = $store->getLatestConversationMessages($conversationId, 10);

    expect($messages)->toHaveCount(5)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls)->toHaveCount(1)
        ->and($messages[0]->toolCalls[0]->reasoningId)->toBe('rs-1')
        ->and($messages[0]->toolCalls[0]->reasoningSummary[0]['text'])->toBe('Check the order')
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults[0]->resultId)->toBe('call-1')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->toolCalls[0]->reasoningId)->toBe('rs-2')
        ->and($messages[3])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[3]->toolResults[0]->resultId)->toBe('call-2')
        ->and($messages[4])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[4]->content)->toBe('The order has shipped.');
});

function createConversationSchema(?string $connection = null): void
{
    $schema = Schema::connection($connection);

    $conversationsTable = config('ai.conversations.tables.conversations', 'agent_conversations');
    $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

    $schema->create($conversationsTable, function (Blueprint $table) {
        $table->string('id', 36)->primary();
        $table->foreignId('user_id')->nullable();
        $table->string('title');
        $table->timestamps();
    });

    $schema->create($messagesTable, function (Blueprint $table) {
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
        $table->timestamps();
    });
}
