<?php

use Illuminate\Http\Client\Request;
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
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('streaming emits text events', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ndjsonPayload([
                $this->chatChunk('Hello'),
                $this->chatChunk(' world'),
                $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 10, 'eval_count' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'application/x-ndjson'],
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

test('streaming request sets stream to true', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ndjsonPayload([
                $this->chatChunk('Hello'),
                $this->chatChunk('', true, 'stop'),
            ]),
            status: 200,
        ),
    ]);

    $this->collectStreamEvents();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

test('streaming handles tool calls', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunkWithToolCalls([
                        $this->toolCallChunk('call_1', 'FixedNumberGenerator'),
                    ]),
                ]),
                status: 200,
            ),
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('The number is 72019'),
                    $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 20, 'eval_count' => 10]),
                ]),
                status: 200,
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->not->toBeEmpty()
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1');
});

test('streaming error event stops stream with string payload', function (): void {
    Http::fake([
        '*' => Http::response(
            body: json_encode(['error' => 'model not found'])."\n",
            status: 200,
        ),
    ]);

    $error = null;

    try {
        $this->collectStreamEvents();
    } catch (StreamErrorException $exception) {
        $error = $exception->error;
    }

    expect($error)->toBeInstanceOf(Error::class)
        ->and($error->type)->toBe('unknown_error')
        ->and($error->message)->toBe('model not found');
});

test('streaming error event also handles structured payload', function (): void {
    Http::fake([
        '*' => Http::response(
            body: json_encode(['error' => ['code' => 'model_error', 'message' => 'Model not found']])."\n",
            status: 200,
        ),
    ]);

    $error = null;

    try {
        $this->collectStreamEvents();
    } catch (StreamErrorException $exception) {
        $error = $exception->error;
    }

    expect($error)->toBeInstanceOf(Error::class)
        ->and($error->type)->toBe('model_error')
        ->and($error->message)->toBe('Model not found');
});

test('streaming accumulates tool call arguments across chunks', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ndjsonPayload([
                    // First chunk: id + name but no arguments yet.
                    [
                        'model' => 'llama3.1:8b',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'function' => [
                                    'name' => 'FixedNumberGenerator',
                                ],
                            ]],
                        ],
                        'done' => false,
                    ],
                    // Second chunk: argument fragment as partial JSON string.
                    [
                        'model' => 'llama3.1:8b',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'function' => [
                                    'arguments' => '{"foo":',
                                ],
                            ]],
                        ],
                        'done' => false,
                    ],
                    // Third chunk: remainder of the arguments payload.
                    [
                        'model' => 'llama3.1:8b',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'function' => [
                                    'arguments' => '"bar"}',
                                ],
                            ]],
                        ],
                        'done_reason' => 'tool_calls',
                        'done' => true,
                        'prompt_eval_count' => 1,
                        'eval_count' => 1,
                    ],
                ]),
                status: 200,
            ),
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('done'),
                    $this->chatChunk('', true, 'stop'),
                ]),
                status: 200,
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->toHaveCount(1)
        ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1')
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->arguments)->toBe(['foo' => 'bar']);
});

test('streaming generates fallback id when tool call has no id', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ndjsonPayload([
                    [
                        'model' => 'llama3.1:8b',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                // No "id" — Ollama sometimes omits it.
                                'function' => [
                                    'name' => 'FixedNumberGenerator',
                                    'arguments' => (object) [],
                                ],
                            ]],
                        ],
                        'done_reason' => 'tool_calls',
                        'done' => true,
                        'prompt_eval_count' => 1,
                        'eval_count' => 1,
                    ],
                ]),
                status: 200,
            ),
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('done'),
                    $this->chatChunk('', true, 'stop'),
                ]),
                status: 200,
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

    expect($toolCallEvents)->toHaveCount(1)
        ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCallEvents[0]->toolCall->id)->not->toBeEmpty();
});

test('streaming captures usage from final chunk', function (): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ndjsonPayload([
                $this->chatChunk('Hello'),
                $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 42, 'eval_count' => 10]),
            ]),
            status: 200,
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->usage->promptTokens)->toBe(42)
        ->and($streamEnd->usage->completionTokens)->toBe(10);
});

test('streaming emits exactly one stream end across a tool loop', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunkWithToolCalls([
                        $this->toolCallChunk('call_1', 'FixedNumberGenerator'),
                    ]),
                ]),
                status: 200,
            ),
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('The number is 72019'),
                    $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 20, 'eval_count' => 10]),
                ]),
                status: 200,
            ),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0])->toBe($events[count($events) - 1])
        ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds[0]->usage->promptTokens)->toBe(30)
        ->and($streamEnds[0]->usage->completionTokens)->toBe(15);
});

test('streaming finish reason maps correctly', function (string $doneReason, $expected): void {
    Http::fake([
        '*' => Http::response(
            body: $this->ndjsonPayload([
                $this->chatChunk('Hello'),
                $this->chatChunk('', true, $doneReason, ['prompt_eval_count' => 10, 'eval_count' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'application/x-ndjson'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

    expect($streamEnd->reason)->toBe($expected->value);
})->with([
    'stop maps to Stop' => ['stop', FinishReason::Stop],
    'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
    'length maps to Length' => ['length', FinishReason::Length],
    'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
]);
