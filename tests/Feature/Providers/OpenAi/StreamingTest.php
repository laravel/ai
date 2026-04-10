<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDelta(' world'),
                $this->outputTextDone('Hello world'),
                $this->responseCompleted(10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[4])->toBeInstanceOf(TextEnd::class)
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                    $this->functionCallArgumentsDelta('fc_1', '{}'),
                    $this->functionCallArgumentsDone('fc_1', '{}'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'response.created',
                        'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []],
                    ],
                    $this->outputTextDelta('The number is 72019'),
                    $this->outputTextDone('The number is 72019'),
                    $this->responseCompleted(20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->resultId)->toBe('call_1');
});

test('streaming handles reasoning events', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                [
                    'type' => 'response.output_item.done',
                    'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Let me think...']]],
                ],
                $this->outputTextDelta('Answer'),
                $this->outputTextDone('Answer'),
                $this->responseCompleted(10, 15),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $types = array_map(fn ($e) => $e::class, $events);

    expect($types)->toContain(ReasoningStart::class)
        ->toContain(ReasoningDelta::class)
        ->toContain(ReasoningEnd::class);

    $reasoningDelta = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))[0];
    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming error event stops stream', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Server overloaded']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(Error::class)
        ->and($events[0]->type)->toBe('server_error')
        ->and($events[0]->message)->toBe('Server overloaded');
});

test('streaming captures usage from response completed', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(42, 10, cachedTokens: 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(37)
        ->and($streamEnd->usage->completionTokens)->toBe(10)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(5);
});
