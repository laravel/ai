<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\RememberingFailingToolAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.key', 'test-key');
});

function anthropicToolTurn(string $id, string $name = 'FixedNumberGenerator'): array
{
    return [
        'id' => 'msg_'.$id,
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

function failedAssistantRow(): ?object
{
    return DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
}

function anthropicToolStream(string $id, string $name = 'FixedNumberGenerator'): string
{
    return implode("\n\n", array_map(fn (array $event): string => 'data: '.json_encode($event), [
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-6', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) []]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
    ]))."\n\n";
}

test('a turn that dies after a tool ran keeps the step and its result', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolTurn('toolu_1'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    expect(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'))
        ->toThrow(RequestException::class);

    $row = failedAssistantRow();

    expect($row->status)->toBe(MessageStatus::Failed->value)
        ->and(json_decode($row->meta, true)['error'])->not->toBeEmpty()
        ->and($row->steps)->json()->toHaveCount(1)
        ->{'0'}->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'toolu_1', 'result' => '72019'])
        ->and(DB::table('agent_conversation_messages')->where('role', 'user')->count())->toBe(1);
});

test('a failed turn replays its answered call and flags the one it never answered', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolTurn('toolu_1'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    expect(fn () => $agent->prompt('Go', provider: 'anthropic'))->toThrow(RequestException::class);

    $conversationId = DB::table('agent_conversations')->value('id');

    $messages = (new DatabaseConversationStore)->getLatestConversationMessages($conversationId, 10);

    expect($messages->last())->toBeInstanceOf(ToolResultMessage::class)
        ->toolResults->toHaveCount(1)->each->toMatchObject(['id' => 'toolu_1', 'result' => '72019'])
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->toolCalls->toHaveCount(1)->each->toMatchObject(['id' => 'toolu_1']);
});

test('a turn that dies before any step records nothing', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

    expect(fn () => (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'))
        ->toThrow(RequestException::class);

    expect(DB::table('agent_conversation_messages')->count())->toBe(0)
        ->and(DB::table('agent_conversations')->count())->toBe(0);
});

test('an agent with nothing to remember records nothing when it dies', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolTurn('toolu_1'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    expect(fn () => (new RememberingToolUsingAgent)->prompt('Go', provider: 'anthropic'))
        ->toThrow(RequestException::class);

    expect(DB::table('agent_conversation_messages')->count())->toBe(0);
});

test('an attempt that fails over to another provider leaves no failed turn behind', function (): void {
    config([
        'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
    ]);

    Http::fakeSequence()
        ->pushStatus(429)
        ->push([
            'id' => 'chat-1',
            'model' => 'llama',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Done.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]);

    (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: ['primary', 'backup']);

    expect(DB::table('agent_conversation_messages')->where('role', 'assistant')->pluck('status')->all())->toBe(['completed']);
});

test('a stream that dies mid-flight keeps the steps it completed', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream'])
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    $stream = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->stream('Go', provider: 'anthropic');

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
            //
        }
    })->toThrow(RequestException::class);

    $row = failedAssistantRow();

    expect($row->status)->toBe(MessageStatus::Failed->value)
        ->and($row->steps)->json()->toHaveCount(1)
        ->{'0'}->tool_calls->toHaveCount(1)->each->toMatchArray(['id' => 'toolu_1', 'result' => '72019']);
});

test('a stream that dies records the conversation the client was already handed', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolStream('toolu_1'), 200, ['Content-Type' => 'text/event-stream'])
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    $stream = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->stream('Go', provider: 'anthropic');

    $surfaced = $stream->conversationId;

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
            //
        }
    })->toThrow(RequestException::class);

    expect($surfaced)->not->toBeNull()
        ->and(DB::table('agent_conversations')->value('id'))->toBe($surfaced);
});

test('a turn that dies keeps the text it had already produced', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Let me generate that number.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    expect(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Go', provider: 'anthropic'))
        ->toThrow(RequestException::class);

    expect(failedAssistantRow()->content)->toBe('Let me generate that number.');
});

test('a step that dies on its second call keeps the result of the first', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'SecretCodeGenerator', 'input' => (object) []],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
        ],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    $agent = (new RememberingFailingToolAgent)->forUser((object) ['id' => 1]);

    expect(fn () => $agent->prompt('Go', provider: 'anthropic'))->toThrow(Exception::class, 'Forced to throw exception.');

    expect(failedAssistantRow()->steps)->json()
        ->{'0'}->tool_calls->{'0'}->toMatchArray(['id' => 'toolu_1', 'result' => 'ZEBRA-4417'])
        ->{'0'}->tool_calls->{'1'}->not->toHaveKey('result');

    $messages = (new DatabaseConversationStore)->getLatestConversationMessages(
        DB::table('agent_conversations')->value('id'), 10,
    );

    expect($messages->last()->toolResults->pluck('result')->all())->toBe([
        'ZEBRA-4417',
        'This tool call was interrupted before a result was recorded, so it may or may not have run.',
    ]);
});

test('a conversation opened by a failed turn is titled the way a completed one is', function (): void {
    Config::set('ai.conversations.generate_title', true);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(anthropicToolTurn('toolu_1'))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500),
    ]);

    $prompt = 'Generate a number for the quarterly report and then explain how you arrived at it in detail';

    expect(fn () => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt($prompt, provider: 'anthropic'))
        ->toThrow(RequestException::class);

    expect(DB::table('agent_conversations')->value('title'))->toContain('arrived at it');
});
