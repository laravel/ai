<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
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

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[0]->toolCalls->keys()->all())->toBe([0, 1])
        ->and($messages[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[1]->toolResults->keys()->all())->toBe([0, 1]);
});

test('it returns the most recently active conversation after storing an assistant message', function () {
    $store = new DatabaseConversationStore;

    Carbon::setTestNow('2026-04-10 10:00:00');
    $conversationA = $store->storeConversation(1, 'Conversation A');

    Carbon::setTestNow('2026-04-10 10:01:00');
    $store->storeConversation(1, 'Conversation B');

    Carbon::setTestNow('2026-04-10 10:02:00');
    $store->storeAssistantMessage(
        $conversationA,
        1,
        databaseConversationStorePrompt(),
        new AgentResponse(
            'invocation-1',
            'Hi',
            new Usage,
            new Meta('openai', 'gpt-5-mini'),
        ),
    );

    expect($store->latestConversationId(1))->toBe($conversationA);
});

test('it returns the most recently active conversation after storing a user message', function () {
    $store = new DatabaseConversationStore;

    Carbon::setTestNow('2026-04-10 10:00:00');
    $conversationA = $store->storeConversation(1, 'Conversation A');

    Carbon::setTestNow('2026-04-10 10:01:00');
    $store->storeConversation(1, 'Conversation B');

    Carbon::setTestNow('2026-04-10 10:02:00');
    $store->storeUserMessage($conversationA, 1, databaseConversationStorePrompt());

    expect($store->latestConversationId(1))->toBe($conversationA);
});

test('it returns a deterministic conversation when updated timestamps match', function () {
    $store = new DatabaseConversationStore;

    Carbon::setTestNow('2026-04-10 10:00:00');

    DB::table('agent_conversations')->insert([
        [
            'id' => '00000000-0000-0000-0000-000000000001',
            'user_id' => 1,
            'title' => 'Conversation A',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => '00000000-0000-0000-0000-000000000002',
            'user_id' => 1,
            'title' => 'Conversation B',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect($store->latestConversationId(1))->toBe('00000000-0000-0000-0000-000000000002');
});

function databaseConversationStorePrompt(): AgentPrompt
{
    return new AgentPrompt(
        new AssistantAgent,
        'Hello',
        [],
        Mockery::mock(TextProvider::class),
        'test-model',
    );
}
