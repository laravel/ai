<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $types = array_map(fn ($e) => $e::class, $events);

    expect($types)->toContain(StreamStart::class)
        ->toContain(TextStart::class)
        ->toContain(TextDelta::class)
        ->toContain(TextEnd::class)
        ->toContain(StreamEnd::class);

    $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));
    expect($textDeltas[0]->delta)->toBe('Hello')
        ->and($textDeltas[1]->delta)->toBe(' world');
});

test('streaming handles tool calls', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10]],
            ])),
            Http::response($this->ssePayload([
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]],
            ])),
        ]),
    ]);

    $events = [];
    foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));
    $streamEndEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($toolCallEvents)->toHaveCount(1)
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->toHaveCount(1)
        ->and($streamEndEvents)->toHaveCount(1)
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value);
});

test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ])),
            Http::response($this->ssePayload([
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
            ])),
        ]),
    ]);

    $events = [];

    foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEndEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEndEvents[0]->usage->promptTokens)->toBe(30)
        ->and($streamEndEvents[0]->usage->completionTokens)->toBe(15);
});

test('streaming error event stops stream', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            ['error' => ['code' => 'server_error', 'message' => 'Internal error']],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $errorEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof Error));
    expect($errorEvents)->toHaveCount(1)
        ->and($errorEvents[0]->type)->toBe('server_error');
});

test('streaming error finish reason emits error event', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Partial'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Provider returned error']]]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $errorEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof Error));
    expect($errorEvents)->toHaveCount(1)
        ->and($errorEvents[0]->type)->toBe('502');
});

test('streaming captures usage from final chunk', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 3]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));
    expect($streamEnd)->toHaveCount(1)
        ->and($streamEnd[0]->usage->promptTokens)->toBe(15)
        ->and($streamEnd[0]->usage->completionTokens)->toBe(3);
});

test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
    'length maps to Length' => ['length', FinishReason::Length],
    'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
    'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
]);

test('streaming emits citation events for web search annotations', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Paris is the capital']),
            $this->chatChunk(['content' => ' of France.', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => [
                        'url' => 'https://example.com/paris',
                        'title' => 'Paris - Wikipedia',
                        'start_index' => 0,
                        'end_index' => 30,
                    ],
                ],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('What is the capital of France?', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn ($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(1)
        ->and($citations[0]->citation->url)->toBe('https://example.com/paris')
        ->and($citations[0]->citation->title)->toBe('Paris - Wikipedia')
        ->and($citations[0]->citation->startIndex)->toBe(0)
        ->and($citations[0]->citation->endIndex)->toBe(30);
});

test('streaming emits multiple citation events across chunks', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => ['url' => 'https://example.com/one', 'title' => 'One'],
                ],
            ]]),
            $this->chatChunk(['content' => ' more', 'annotations' => [
                [
                    'type' => 'url_citation',
                    'url_citation' => ['url' => 'https://example.com/two', 'title' => 'Two'],
                ],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn ($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(2)
        ->and($citations[0]->citation->url)->toBe('https://example.com/one')
        ->and($citations[1]->citation->url)->toBe('https://example.com/two');
});

test('streaming ignores non-url-citation annotation types', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                ['type' => 'other_type', 'data' => 'something'],
            ]]),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
        ])),
    ]);

    $events = [];
    foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $citations = array_values(array_filter($events, fn ($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(0);
});
