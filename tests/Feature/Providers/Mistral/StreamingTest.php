<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolInputDelta;
use Laravel\Ai\Streaming\Events\ToolInputStart;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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

test('streaming flattens block content deltas', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [['type' => 'reference', 'reference_ids' => ['search_documents']]]], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextEnd::class)
        ->and($events[4])->toBeInstanceOf(StreamEnd::class);
});

test('streaming handles tool calls', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));
    $streamEndEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->not->toBeEmpty()
        ->and($streamEndEvents)->toHaveCount(1)
        ->and($streamEndEvents[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEndEvents[0]->usage->inputTokens)->toBe(30)
        ->and($streamEndEvents[0]->usage->outputTokens)->toBe(15);
});

test('streaming emits the tool input as it arrives, before the tool call', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    // The opening chunk carries an empty arguments fragment, which carries no delta...
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"sta']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'rt":1}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = array_values(array_filter(
        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent),
        fn ($event): bool => $event instanceof ToolInputStart || $event instanceof ToolInputDelta || $event instanceof ToolCallEvent,
    ));

    expect($events)->toHaveCount(4)
        ->and($events[0])->toBeInstanceOf(ToolInputStart::class)
        ->and($events[0])->toolId->toBe('call_1')->toolName->toBe('FixedNumberGenerator')
        ->and($events[1])->toBeInstanceOf(ToolInputDelta::class)
        ->and($events[1])->toolId->toBe('call_1')->delta->toBe('{"sta')
        ->and($events[2])->toBeInstanceOf(ToolInputDelta::class)
        ->and($events[2])->toolId->toBe('call_1')->delta->toBe('rt":1}')
        ->and($events[3])->toBeInstanceOf(ToolCallEvent::class)
        ->and($events[3]->toolCall)->id->toBe('call_1')->arguments->toBe(['start' => 1]);
});

test('streaming captures usage', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->inputTokens)->toBe(10)
        ->and($streamEnd->usage->outputTokens)->toBe(5);
});

test('streaming error event stops stream', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['error' => ['code' => 'server_error', 'message' => 'Internal server error']],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $error = null;

    try {
        $this->collectStreamEvents();
    } catch (StreamErrorException $exception) {
        $error = $exception->error;
    }

    expect($error)->toBeInstanceOf(Error::class)
        ->and($error->type)->toBe('server_error')
        ->and($error->message)->toBe('Internal server error');
});

test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
    'length maps to Length' => ['length', FinishReason::Length],
    'model_length maps to Length' => ['model_length', FinishReason::Length],
    'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
    'error maps to Error' => ['error', FinishReason::Error],
    'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
]);
