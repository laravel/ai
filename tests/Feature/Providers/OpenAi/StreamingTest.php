<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
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
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

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
                    $this->responseCompleted(10, 5, output: [
                        ['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]),
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
    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->resultId)->toBe('call_1')
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnd->usage->promptTokens)->toBe(30)
        ->and($streamEnd->usage->completionTokens)->toBe(15);
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

test('streaming finish reason maps correctly', function (string $status, string $type, $expected) {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(10, 5, output: [
                    ['type' => $type, 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]],
                ]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'completed message maps to Stop' => ['completed', 'message', FinishReason::Stop],
    'completed function_call maps to ToolCalls' => ['completed', 'function_call', FinishReason::ToolCalls],
    'incomplete maps to Length' => ['incomplete', 'message', FinishReason::Length],
    'failed maps to Error' => ['failed', 'message', FinishReason::Error],
    'unknown status maps to Unknown' => ['mystery_status', 'message', FinishReason::Unknown],
    'completed unknown type maps to Unknown' => ['completed', 'mystery_output', FinishReason::Unknown],
]);

describe('citations', function () {
    test('streaming emits citation events from url_citation annotations in response.completed', function () {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Here are sources'),
                    $this->outputTextDone('Here are sources'),
                    $this->responseCompleted(10, 5, output: [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'Here are sources',
                            'annotations' => [
                                ['type' => 'url_citation', 'url' => 'https://example.com/a', 'title' => 'A', 'start_index' => 0, 'end_index' => 5],
                                ['type' => 'url_citation', 'url' => 'https://example.com/b', 'title' => 'B', 'start_index' => 6, 'end_index' => 11],
                            ],
                        ]],
                    ]]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citationEvents = array_values(array_filter($events, fn ($e) => $e instanceof \Laravel\Ai\Streaming\Events\Citation));

        expect($citationEvents)->toHaveCount(2)
            ->and($citationEvents[0]->citation->url)->toBe('https://example.com/a')
            ->and($citationEvents[0]->citation->ranges->all())->toBe([[0, 5]])
            ->and($citationEvents[1]->citation->url)->toBe('https://example.com/b');
    });

    test('streaming deduplicates citations by url and accumulates ranges', function () {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDone('text'),
                    $this->responseCompleted(10, 5, output: [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'text',
                            'annotations' => [
                                ['type' => 'url_citation', 'url' => 'https://example.com/same', 'title' => 'X', 'start_index' => 0, 'end_index' => 4],
                                ['type' => 'url_citation', 'url' => 'https://example.com/same', 'title' => 'X', 'start_index' => 10, 'end_index' => 20],
                            ],
                        ]],
                    ]]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citationEvents = array_values(array_filter($events, fn ($e) => $e instanceof \Laravel\Ai\Streaming\Events\Citation));

        expect($citationEvents)->toHaveCount(1)
            ->and($citationEvents[0]->citation->url)->toBe('https://example.com/same')
            ->and($citationEvents[0]->citation->ranges->all())->toBe([[0, 4], [10, 20]]);
    });

    test('citation events appear after text events and before stream end', function () {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(10, 5, output: [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'Answer',
                            'annotations' => [
                                ['type' => 'url_citation', 'url' => 'https://example.com/ref', 'title' => 'Ref', 'start_index' => 0, 'end_index' => 6],
                            ],
                        ]],
                    ]]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();
        $types = array_map(fn ($e) => get_class($e), $events);

        $textEndPos  = array_search(\Laravel\Ai\Streaming\Events\TextEnd::class, $types);
        $citationPos = array_search(\Laravel\Ai\Streaming\Events\Citation::class, $types);
        $streamEndPos = array_search(\Laravel\Ai\Streaming\Events\StreamEnd::class, $types);

        expect($citationPos)->toBeGreaterThan($textEndPos)
            ->and($citationPos)->toBeLessThan($streamEndPos);
    });
});
