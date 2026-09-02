<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
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

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function (): void {
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

test('streaming emits citation events for web search url citations', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Here are sources'),
                ['type' => 'response.output_text.annotation.added', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'annotation_index' => 0, 'annotation' => ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Example One', 'start_index' => 0, 'end_index' => 10]],
                ['type' => 'response.output_text.annotation.added', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'annotation_index' => 1, 'annotation' => ['type' => 'url_citation', 'url' => 'https://example.com/two', 'title' => 'Example Two', 'start_index' => 11, 'end_index' => 25]],
                $this->outputTextDone('Here are sources'),
                $this->responseCompleted(10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $citations = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof CitationEvent));

    expect($citations)->toHaveCount(2)
        ->and($citations[0]->citation->url)->toBe('https://example.com/one')
        ->and($citations[0]->citation->title)->toBe('Example One')
        ->and($citations[0]->citation->startIndex)->toBe(0)
        ->and($citations[0]->citation->endIndex)->toBe(10)
        ->and($citations[1]->citation->url)->toBe('https://example.com/two');
});

test('streaming starts a new text part after each text end in the same step', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('First'),
                $this->outputTextDone('First'),
                $this->outputTextDelta('Second'),
                $this->outputTextDone('Second'),
                $this->responseCompleted(10, 5, output: [
                    [
                        'type' => 'message',
                        'status' => 'completed',
                        'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'First'],
                            ['type' => 'output_text', 'text' => 'Second'],
                        ],
                    ],
                ]),
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

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->resultId)->toBe('call_1')
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnd->usage->promptTokens)->toBe(30)
        ->and($streamEnd->usage->completionTokens)->toBe(15);
});

test('streaming handles reasoning events', function (): void {
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

    $reasoningDelta = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta))[0];
    expect($reasoningDelta->delta)->toBe('Let me think...');
});

test('streaming error event stops stream', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                ['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Server overloaded']],
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
        ->and($error->message)->toBe('Server overloaded');
});

test('streaming captures usage from response completed', function (): void {
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

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(37)
        ->and($streamEnd->usage->completionTokens)->toBe(10)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(5);
});

test('streaming finish reason maps correctly', function (string $status, string $type, $expected): void {
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

test('streaming captures cache write tokens from response completed', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Hello'),
                $this->outputTextDone('Hello'),
                $this->responseCompleted(8817, 120, cachedTokens: 0, cacheWriteTokens: 8814),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->cacheWriteInputTokens)->toBe(8814)
        ->and($streamEnd->usage->cacheReadInputTokens)->toBe(0)
        ->and($streamEnd->usage->promptTokens)->toBe(3)
        ->and($streamEnd->usage->completionTokens)->toBe(120);
});

test('streaming emits provider tool events for code interpreter code deltas', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                ['type' => 'response.code_interpreter_call.in_progress', 'item_id' => 'ci_1', 'output_index' => 0],
                ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'ci_1', 'output_index' => 0, 'delta' => 'print(1)'],
                ['type' => 'response.code_interpreter_call_code.done', 'item_id' => 'ci_1', 'output_index' => 0, 'code' => 'print(1)'],
                ['type' => 'response.code_interpreter_call.completed', 'item_id' => 'ci_1', 'output_index' => 0],
                $this->outputTextDelta('1'),
                $this->outputTextDone('1'),
                $this->responseCompleted(10, 5),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof ProviderToolEvent));

    expect(array_map(fn (ProviderToolEvent $e): string => $e->status, $providerEvents))
        ->toBe(['in_progress', 'code_delta', 'code_done', 'completed'])
        ->and($providerEvents[1])->type->toBe('code_interpreter_call')->itemId->toBe('ci_1')
        ->and($providerEvents[1]->data['delta'])->toBe('print(1)');
});
