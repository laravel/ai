<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Feature\Providers\DeepSeek\DeepSeekHelpers;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

uses(DeepSeekHelpers::class);

beforeEach(function () {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response(
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

test('streaming handles tool calls', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::sequence([
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
        'api.deepseek.com/*' => Http::response(
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
        ->and($events[0]->type)->toBe('rate_limit_exceeded')
        ->and($events[0]->message)->toBe('Rate limit exceeded');
});

test('streaming captures usage from final chunk', function () {
    Http::fake([
        'api.deepseek.com/*' => Http::response(
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

test('streaming finish reason maps correctly', function (string $apiReason, $expected) {
    Http::fake([
        'api.deepseek.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish($apiReason, ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'length maps to Length' => ['length', FinishReason::Length],
    'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
]);
