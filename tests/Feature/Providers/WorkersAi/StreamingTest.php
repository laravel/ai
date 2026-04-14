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
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('streaming emits text events', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunk(['content' => ' world']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
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

test('streaming emits reasoning events for thinking models', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunkReasoning('The user wants'),
                $this->chatChunkReasoning(' me to say hello.'),
                $this->chatChunk(['content' => 'Hello!']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 14, 'completion_tokens' => 30]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $eventTypes = array_map(fn ($e) => get_class($e), $events);

    expect($eventTypes)->toContain(ReasoningStart::class)
        ->and($eventTypes)->toContain(ReasoningDelta::class)
        ->and($eventTypes)->toContain(ReasoningEnd::class)
        ->and($eventTypes)->toContain(TextStart::class)
        ->and($eventTypes)->toContain(TextDelta::class);

    $reasoningStartIdx = array_search(ReasoningStart::class, $eventTypes);
    $textStartIdx = array_search(TextStart::class, $eventTypes);
    expect($reasoningStartIdx)->toBeLessThan($textStartIdx);
});

test('streaming handles tool calls', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                    '[DONE]',
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
        ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1');
});

test('streaming error event stops stream', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(Error::class)
        ->and($events[0]->type)->toBe('rate_limit_exceeded');
});

test('streaming captures usage from final chunk', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 42, 'completion_tokens' => 10]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(42)
        ->and($streamEnd->usage->completionTokens)->toBe(10);
});
