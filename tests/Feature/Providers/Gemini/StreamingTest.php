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
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;
use Tests\Feature\Providers\Gemini\GeminiHelpers;

uses(GeminiHelpers::class);

test('streaming emits text events', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            body: geminiSsePayload([
                geminiChunk([['text' => 'Hello']]),
                geminiChunk([['text' => ' world']]),
                geminiChunkWithUsage([['text' => '']], 10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = geminiCollectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class);
    expect($events[1])->toBeInstanceOf(TextStart::class);
    expect($events[2])->toBeInstanceOf(TextDelta::class);
    expect($events[2]->delta)->toBe('Hello');
    expect($events[3])->toBeInstanceOf(TextDelta::class);
    expect($events[3]->delta)->toBe(' world');
    expect($events[count($events) - 2])->toBeInstanceOf(TextEnd::class);
    expect($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response(
                body: geminiSsePayload([
                    geminiChunkWithUsage([[
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
                body: geminiSsePayload([
                    geminiChunkWithUsage([['text' => 'The number is 72019']], 20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = geminiCollectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty();
    expect($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator');
});

test('streaming handles thinking parts', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            body: geminiSsePayload([
                geminiChunk([['text' => 'Let me think...', 'thought' => true]]),
                geminiChunk([['text' => 'Answer']]),
                geminiChunkWithUsage([], 10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = geminiCollectStreamEvents();

    $types = array_map(fn ($e) => $e::class, $events);

    expect($types)->toContain(ReasoningStart::class);
    expect($types)->toContain(ReasoningDelta::class);
    expect($types)->toContain(ReasoningEnd::class);

    $reasoningDelta = array_values(array_filter($events, fn ($e) => $e instanceof ReasoningDelta))[0];
    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming error event stops stream', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            body: geminiSsePayload([
                ['error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = geminiCollectStreamEvents();

    expect($events)->toHaveCount(1);
    expect($events[0])->toBeInstanceOf(Error::class);
    expect($events[0]->type)->toBe('overloaded');
    expect($events[0]->message)->toBe('Server overloaded');
});

test('streaming captures usage from final chunk', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            body: geminiSsePayload([
                geminiChunk([['text' => 'Hello']]),
                geminiChunkWithUsage([['text' => '']], 42, 10, cachedTokens: 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = geminiCollectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(37);
    expect($streamEnd->usage->completionTokens)->toBe(10);
    expect($streamEnd->usage->cacheReadInputTokens)->toBe(5);
});

test('streaming thinking parts are excluded from tool call continuation', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            Http::response(
                body: geminiSsePayload([
                    geminiChunk([
                        ['text' => 'thinking...', 'thought' => true],
                        ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                    ]),
                    geminiChunkWithUsage([], 10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: geminiSsePayload([
                    geminiChunkWithUsage([['text' => 'Done']], 20, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    geminiCollectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

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

test('streaming uses sse endpoint with alt parameter', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            body: geminiSsePayload([
                geminiChunkWithUsage([['text' => 'Hello']], 10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    geminiCollectStreamEvents();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'streamGenerateContent?alt=sse');
    });
});

/**
 * Collect all stream events from the agent's stream response.
 */
function geminiCollectStreamEvents(?object $agent = null): array
{
    $agent ??= new AssistantAgent;

    $response = $agent->stream(
        'Hello',
        provider: 'gemini',
    );

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    return $events;
}

function geminiSsePayload(array $events): string
{
    $lines = [];

    foreach ($events as $event) {
        $lines[] = 'data: '.json_encode($event);
    }

    return implode("\n\n", $lines)."\n\n";
}

function geminiChunk(array $parts, ?string $modelVersion = null): array
{
    return [
        'candidates' => [[
            'content' => [
                'parts' => $parts,
                'role' => 'model',
            ],
        ]],
        'modelVersion' => $modelVersion ?? 'gemini-3-flash-preview',
    ];
}

function geminiChunkWithUsage(array $parts, int $promptTokens, int $candidatesTokens, int $cachedTokens = 0, ?string $modelVersion = null): array
{
    $chunk = geminiChunk($parts, $modelVersion);

    $chunk['usageMetadata'] = array_filter([
        'promptTokenCount' => $promptTokens,
        'candidatesTokenCount' => $candidatesTokens,
        'totalTokenCount' => $promptTokens + $candidatesTokens,
        'cachedContentTokenCount' => $cachedTokens ?: null,
    ]);

    return $chunk;
}
