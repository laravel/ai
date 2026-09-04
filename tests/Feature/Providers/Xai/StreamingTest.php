<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function (): void {
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

test('streaming emits citation events from the completed response', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'Here are sources'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Here are sources', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Example One', 'start_index' => 0, 'end_index' => 10],
                    ['type' => 'url_citation', 'url' => 'https://example.com/two', 'title' => 'Example Two', 'start_index' => 11, 'end_index' => 25],
                ]]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();
    $textStart = collect($events)->first(fn ($event): bool => $event instanceof TextStart);
    $citations = array_values(array_filter($events, fn ($event): bool => $event instanceof CitationEvent));

    expect($citations)->toHaveCount(2)
        ->and($citations[0]->messageId)->toBe($textStart->messageId)
        ->and($citations[1]->messageId)->toBe($textStart->messageId)
        ->and($citations[0]->citation->url)->toBe('https://example.com/one')
        ->and($citations[0]->citation->title)->toBe('Example One')
        ->and($citations[0]->citation->startIndex)->toBe(0)
        ->and($citations[1]->citation->url)->toBe('https://example.com/two');
});

test('streaming starts a new text part after each text end in the same step', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.output_text.delta', 'delta' => 'First'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.output_text.delta', 'delta' => 'Second'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'FirstSecond']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $textStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof TextStart));
    $textEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof TextEnd));
    $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));

    expect($textStarts)->toHaveCount(2)
        ->and($textEnds)->toHaveCount(2)
        ->and($textDeltas)->toHaveCount(2)
        ->and($textStarts[0]->messageId)->not->toBe($textStarts[1]->messageId)
        ->and($textEnds[0]->messageId)->toBe($textStarts[0]->messageId)
        ->and($textEnds[1]->messageId)->toBe($textStarts[1]->messageId)
        ->and($textDeltas[0]->messageId)->toBe($textStarts[0]->messageId)
        ->and($textDeltas[1]->messageId)->toBe($textStarts[1]->messageId);
});

test('streaming handles tool calls', function (): void {
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

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
    $toolResultEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolResultEvents)->not->toBeEmpty();
});

test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                    ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                    ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 8], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds[0]->usage->promptTokens)->toBe(20)
        ->and($streamEnds[0]->usage->completionTokens)->toBe(15)
        ->and($streamEnds[0]->usage->cacheReadInputTokens)->toBe(10);
});

test('streaming captures usage', function (): void {
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

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(8); // 10 - 2 cached
    expect($streamEnd->usage->completionTokens)->toBe(5);
    expect($streamEnd->usage->cacheReadInputTokens)->toBe(2)
        ->and($streamEnd->usage->reasoningTokens)->toBe(3);
});

test('streaming error event stops stream', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']],
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

test('streaming finish reason maps correctly', function (string $status, string $type, $expected): void {
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

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'completed message maps to Stop' => ['completed', 'message', FinishReason::Stop],
    'completed function_call maps to ToolCalls' => ['completed', 'function_call', FinishReason::ToolCalls],
    'incomplete maps to Length' => ['incomplete', 'message', FinishReason::Length],
    'failed maps to Error' => ['failed', 'message', FinishReason::Error],
    'unknown status maps to Unknown' => ['mystery_status', 'message', FinishReason::Unknown],
    'completed unknown type maps to Unknown' => ['completed', 'mystery_output', FinishReason::Unknown],
]);

test('streaming emits provider tool events for code interpreter code deltas', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'ci_1', 'output_index' => 0, 'delta' => 'print(1)'],
                ['type' => 'response.code_interpreter_call_code.done', 'item_id' => 'ci_1', 'output_index' => 0, 'code' => 'print(1)'],
                ['type' => 'response.output_text.delta', 'delta' => '1'],
                ['type' => 'response.output_text.done'],
                ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '1']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof ProviderToolEvent));

    expect(array_map(fn (ProviderToolEvent $e): string => $e->status, $providerEvents))->toBe(['code_delta', 'code_done'])
        ->and($providerEvents[0])->type->toBe('code_interpreter_call')->itemId->toBe('ci_1');
});
