<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
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

describe('text streaming', function () {
    test('streaming emits text events', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunk([['text' => ' world']]),
                    $this->geminiChunkWithUsage([['text' => '']], 10, 5),
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
            ->and($events[count($events) - 2])->toBeInstanceOf(TextEnd::class)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });

    test('streaming uses sse endpoint with alt parameter', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunkWithUsage([['text' => 'Hello']], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $this->collectStreamEvents();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'streamGenerateContent?alt=sse');
        });
    });
});

describe('tool calls', function () {
    test('streaming handles tool calls', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([[
                            'functionCall' => [
                                'id' => 'call_1',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator');
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([[
                            'functionCall' => [
                                'id' => 'call_1',
                                'name' => 'FixedNumberGenerator',
                                'args' => (object) [],
                            ],
                        ]], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        expect($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
            ->and($streamEnds[0]->usage)
            ->promptTokens->toBe(30)
            ->completionTokens->toBe(15);
    });

    test('streaming thinking parts are excluded from tool call continuation', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunk([
                            ['text' => 'thinking...', 'thought' => true],
                            ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                        ]),
                        $this->geminiChunkWithUsage([], 10, 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->geminiChunkWithUsage([['text' => 'Done']], 20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $recorded = Http::recorded();
        expect($recorded)->toHaveCount(2);

        $followUpContents = $recorded[1][0]->data()['contents'];

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    expect($part['thought'] ?? false)->toBeFalse('Streaming: thinking parts should be excluded from tool call continuation');
                }
            }
        }
    });
});

describe('thinking blocks', function () {
    test('streaming handles thinking parts', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Let me think...', 'thought' => true]]),
                    $this->geminiChunk([['text' => 'Answer']]),
                    $this->geminiChunkWithUsage([], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        expect($events)->toContainStreamEventTypes([
            ReasoningStart::class,
            ReasoningDelta::class,
            ReasoningEnd::class,
        ]);

        $reasoningDelta = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))[0];
        expect($reasoningDelta->delta)->toBe('Let me think...');
    });
});

describe('error handling', function () {
    test('streaming error event stops stream', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBeInstanceOf(Error::class)->type->toBe('overloaded')->message->toBe('Server overloaded');
    });
});

describe('usage tracking', function () {
    test('streaming captures usage from final chunk', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunkWithUsage([['text' => '']], 42, 10, cachedTokens: 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->promptTokens->toBe(37)
            ->completionTokens->toBe(10)
            ->cacheReadInputTokens->toBe(5);
    });

    test('streaming finish reason maps correctly', function (string $geminiReason, $expected) {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Hello']]),
                    $this->geminiChunkWithUsage([['text' => '']], 10, 5, finishReason: $geminiReason),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'STOP maps to Stop' => ['STOP', FinishReason::Stop],
        'MAX_TOKENS maps to Length' => ['MAX_TOKENS', FinishReason::Length],
        'SAFETY maps to ContentFilter' => ['SAFETY', FinishReason::ContentFilter],
        'MALFORMED_FUNCTION_CALL maps to ContentFilter' => ['MALFORMED_FUNCTION_CALL', FinishReason::ContentFilter],
        'RECITATION maps to ContentFilter' => ['RECITATION', FinishReason::ContentFilter],
    ]);
});

describe('citations', function () {
    test('streaming emits citation events from grounding metadata in the final chunk', function () {
        $finalChunk = $this->geminiChunkWithUsage([['text' => 'Spain won Euro 2024.']], 10, 5, finishReason: 'STOP');
        $finalChunk['candidates'][0]['groundingMetadata'] = [
            'groundingChunks' => [
                ['web' => ['uri' => 'https://example.com/euro', 'title' => 'Euro 2024']],
                ['web' => ['uri' => 'https://example.com/spain', 'title' => 'Spain Wins']],
            ],
            'groundingSupports' => [
                ['segment' => ['startIndex' => 0, 'endIndex' => 20], 'groundingChunkIndices' => [0, 1]],
            ],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->geminiChunk([['text' => 'Spain won']]),
                    $finalChunk,
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citationEvents = array_values(array_filter($events, fn ($e) => $e instanceof CitationEvent));

        expect($citationEvents)->toHaveCount(2)
            ->and($citationEvents[0]->citation->url)->toBe('https://example.com/euro')
            ->and($citationEvents[0]->citation->isByteOffset)->toBeTrue()
            ->and($citationEvents[0]->citation->ranges->all())->toBe([[0, 20]])
            ->and($citationEvents[1]->citation->url)->toBe('https://example.com/spain');
    });

    test('streaming emits citation events from legacy citationMetadata', function () {
        $finalChunk = $this->geminiChunkWithUsage([['text' => 'Some content.']], 10, 5, finishReason: 'STOP');
        $finalChunk['candidates'][0]['citationMetadata'] = [
            'citationSources' => [
                ['uri' => 'https://example.com/source', 'title' => 'The Source'],
            ],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([$finalChunk]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citationEvents = array_values(array_filter($events, fn ($e) => $e instanceof CitationEvent));

        expect($citationEvents)->toHaveCount(1)
            ->and($citationEvents[0]->citation->url)->toBe('https://example.com/source')
            ->and($citationEvents[0]->citation->isByteOffset)->toBeFalse();
    });

    test('citation events appear after text events and before stream end', function () {
        $finalChunk = $this->geminiChunkWithUsage([['text' => 'Answer.']], 10, 5, finishReason: 'STOP');
        $finalChunk['candidates'][0]['groundingMetadata'] = [
            'groundingChunks' => [
                ['web' => ['uri' => 'https://example.com/ref', 'title' => 'Ref']],
            ],
            'groundingSupports' => [
                ['segment' => ['startIndex' => 0, 'endIndex' => 7], 'groundingChunkIndices' => [0]],
            ],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                body: $this->ssePayload([$finalChunk]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();
        $types = array_map(fn ($e) => get_class($e), $events);

        $textEndPos = array_search(\Laravel\Ai\Streaming\Events\TextEnd::class, $types);
        $citationPos = array_search(CitationEvent::class, $types);
        $streamEndPos = array_search(\Laravel\Ai\Streaming\Events\StreamEnd::class, $types);

        expect($citationPos)->toBeGreaterThan($textEndPos)
            ->and($citationPos)->toBeLessThan($streamEndPos);
    });
});
