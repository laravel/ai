<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

beforeEach(fn () => $this->configureOracle());

test('cohere streaming emits text events', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::response(
            body: $this->ssePayload([
                ['text' => 'Hello'],
                ['text' => ' world'],
                ['finishReason' => 'COMPLETE', 'usage' => ['promptTokens' => 10, 'completionTokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents(model: 'cohere.command-a-03-2025');

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[count($events) - 2])->toBeInstanceOf(TextEnd::class)
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);

    expect($events[count($events) - 1]->usage->promptTokens)->toBe(10);
});

test('generic streaming emits text events from content part deltas', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::response(
            body: $this->ssePayload([
                ['message' => ['content' => [['type' => 'TEXT', 'text' => 'Hi']]]],
                ['message' => ['content' => [['type' => 'TEXT', 'text' => ' there']]]],
                ['finishReason' => 'stop', 'usage' => ['promptTokens' => 8, 'completionTokens' => 3]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents(model: 'meta.llama-3.3-70b-instruct');

    $deltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));

    expect($deltas[0]->delta)->toBe('Hi')
        ->and($deltas[1]->delta)->toBe(' there')
        ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
});

test('streaming requests set isStream on the chat request', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::response(
            body: $this->ssePayload([['text' => 'Hello'], ['finishReason' => 'COMPLETE']]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents(model: 'cohere.command-a-03-2025');

    Http::assertSent(fn ($request) => ($request->data()['chatRequest']['isStream'] ?? false) === true);
});
