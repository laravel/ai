<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    configureOpenAiCompatible();
});

test('prompt reads reasoning off the response', function (string $field): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello', $field => 'Let me think...'],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $response = agent()->prompt('Hi', provider: 'openai-compatible');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
})->with(['reasoning_content', 'reasoning']);

test('streaming emits reasoning events before the text', function (string $field): void {
    $chunk = fn (array $delta, ?string $finish = null): string => 'data: '.json_encode([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ]);

    Http::fake(['*' => Http::response(implode("\n\n", [
        $chunk(['role' => 'assistant', $field => 'Let me ']),
        $chunk([$field => 'think...']),
        $chunk(['content' => 'Hello']),
        $chunk([], 'stop'),
        'data: [DONE]',
    ])."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

    $events = [];

    foreach (agent()->stream('Hi', provider: 'openai-compatible') as $event) {
        $events[] = $event;
    }

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
})->with(['reasoning_content', 'reasoning']);

function openAiCompatibleToolCallResponse(array $reasoning): array
{
    return [
        'id' => 'chatcmpl-tool',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                ...$reasoning,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];
}

function openAiCompatibleTextResponse(string $text): array
{
    return [
        'id' => 'chatcmpl-text',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ];
}

function openAiCompatibleReplayedToolCallMessage(): array
{
    $messages = json_decode(Http::recorded()[1][0]->body(), true)['messages'];

    return collect($messages)->first(fn (array $message): bool => $message['role'] === 'assistant' && isset($message['tool_calls']));
}

test('tool loops send reasoning back under the field the server used', function (string $field): void {
    Http::fake(['*' => Http::sequence([
        Http::response(openAiCompatibleToolCallResponse([$field => 'I need a number.'])),
        Http::response(openAiCompatibleTextResponse('The number is 72019')),
    ])]);

    (new ProviderOptionsWithToolsAgent)->prompt('Generate a number', provider: 'openai-compatible');

    expect(openAiCompatibleReplayedToolCallMessage())->toBe([
        'role' => 'assistant',
        'tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
        ]],
        $field => 'I need a number.',
    ]);
})->with(['reasoning_content', 'reasoning']);

test('streamed tool loops send reasoning back under the field the server used', function (string $field): void {
    $chunk = fn (array $delta, ?string $finish = null): string => 'data: '.json_encode([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ]);

    Http::fake(['*' => Http::sequence([
        Http::response(implode("\n\n", [
            $chunk(['role' => 'assistant', $field => 'I need ']),
            $chunk([$field => 'a number.']),
            $chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}']]]]),
            $chunk([], 'tool_calls'),
            'data: [DONE]',
        ])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
        Http::response(implode("\n\n", [
            $chunk(['role' => 'assistant', 'content' => 'The number is 72019']),
            $chunk([], 'stop'),
            'data: [DONE]',
        ])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
    ])]);

    iterator_to_array((new ProviderOptionsWithToolsAgent)->stream('Generate a number', provider: 'openai-compatible'));

    expect(openAiCompatibleReplayedToolCallMessage()[$field])->toBe('I need a number.');
})->with(['reasoning_content', 'reasoning']);

test('tool loops send no reasoning when the server returned none', function (): void {
    Http::fake(['*' => Http::sequence([
        Http::response(openAiCompatibleToolCallResponse([])),
        Http::response(openAiCompatibleTextResponse('The number is 72019')),
    ])]);

    (new ProviderOptionsWithToolsAgent)->prompt('Generate a number', provider: 'openai-compatible');

    expect(openAiCompatibleReplayedToolCallMessage())->not->toHaveKeys(['reasoning', 'reasoning_content']);
});
