<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                ['type' => 'response.output_text.delta', 'delta' => ' world'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
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
        ->and($events[5])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function () {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                    ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->not->toBeEmpty();
});

test('streaming captures usage', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(8); // 10 - 2 cached
    expect($streamEnd->usage->completionTokens)->toBe(5);
    expect($streamEnd->usage->cacheReadInputTokens)->toBe(2)
        ->and($streamEnd->usage->reasoningTokens)->toBe(3);
});

test('streaming error event stops stream', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(Error::class)
        ->and($events[0]->type)->toBe('server_error')
        ->and($events[0]->message)->toBe('Internal server error');
});

test('streaming rate limit error event throws a rate limited exception', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'error', 'code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached on tokens per min (TPM).'],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    try {
        $this->collectStreamEvents();

        $this->fail('Expected RateLimitedException to be thrown.');
    } catch (RateLimitedException $e) {
        expect($e->provider())->toBe('xai')
            ->and($e->status())->toBe(429);
    }
});

test('streaming response.failed with rate limit throws a rate limited exception', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.failed', 'response' => [
                    'status' => 'failed',
                    'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached.'],
                ]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    expect(fn () => $this->collectStreamEvents())->toThrow(RateLimitedException::class);
});

test('streaming response.failed with insufficient quota throws an insufficient credits exception', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.failed', 'response' => [
                    'status' => 'failed',
                    'error' => ['code' => 'insufficient_quota', 'message' => 'You exceeded your current quota.'],
                ]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    expect(fn () => $this->collectStreamEvents())->toThrow(InsufficientCreditsException::class);
});

test('streaming response.incomplete ends the stream with usage instead of emptiness', function () {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'grok-4-1-fast-reasoning', 'status' => 'in_progress']],
                ['type' => 'response.incomplete', 'response' => [
                    'id' => 'resp_1',
                    'status' => 'incomplete',
                    'incomplete_details' => ['reason' => 'max_output_tokens'],
                    'usage' => [
                        'input_tokens' => 12,
                        'output_tokens' => 8,
                        'input_tokens_details' => ['cached_tokens' => 0],
                    ],
                ]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe(FinishReason::Length->value)
        ->and($streamEnd->usage->promptTokens)->toBe(12)
        ->and($streamEnd->usage->completionTokens)->toBe(8);
});

test('streaming finish reason maps correctly', function (string $status, string $type, $expected) {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => $status, 'output' => [['type' => $type, 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
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
