<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\FinishReason;
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

describe('text streaming', function (): void {
    test('streaming emits text events', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
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
});

describe('tool calls', function (): void {
    test('streaming handles tool calls', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('tool_use', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall)->name->toBe('FixedNumberGenerator')->id->toBe('toolu_1');
    });
});

describe('thinking blocks', function (): void {
    test('streaming handles thinking blocks', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
                    $this->contentBlockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me think...']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Answer']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 15),
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

        $reasoningDelta = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta))[0];
        expect($reasoningDelta->delta)->toBe('Let me think...');
    });

    test('streaming handles server tool use', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Result']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ProviderToolEvent));

        expect($providerEvents)->toHaveCount(2)
            ->and($providerEvents[0])->status->toBe('started')->itemId->toBe('srvtoolu_1')
            ->and($providerEvents[1]->status)->toBe('completed');
    });

    test('streaming handles provider tool results', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'search_results' => []]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Found it']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ProviderToolEvent));

        expect($providerEvents)->not->toBeEmpty()
            ->and($providerEvents[0])->status->toBe('result_received')->type->toBe('web_search_tool_result');
    });
});

describe('pause_turn', function (): void {
    test('streaming pause_turn triggers follow-up stream with assistant replayed', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_pause', 'name' => 'web_search']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{"query":"laravel ai"}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('pause_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                        $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Resumed']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('end_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $recorded = Http::recorded();
        expect($recorded)->toHaveCount(2);

        $followUpBody = $recorded[1][0]->data();
        $lastMessage = end($followUpBody['messages']);

        expect($lastMessage['role'])->toBe('assistant')
            ->and($lastMessage['content'][0]['type'])->toBe('server_tool_use')
            ->and($lastMessage['content'][0]['input'])->toBeInstanceOf(stdClass::class)
            ->and($lastMessage['content'][0]['input']->query)->toBe('laravel ai');

        $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));
        expect($textDeltas)->not->toBeEmpty()
            ->and($textDeltas[0]->delta)->toBe('Resumed');
    });
});

describe('error handling', function (): void {
    test('streaming error event stops stream', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']],
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

        expect($error)->toBeInstanceOf(Error::class)->type->toBe('overloaded_error')->message->toBe('Server overloaded');
    });
});

describe('usage tracking', function (): void {
    test('streaming captures input tokens from message start', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_1',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 42,
                                'output_tokens' => 0,
                                'cache_creation_input_tokens' => 100,
                                'cache_read_input_tokens' => 50,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->promptTokens->toBe(42)
            ->completionTokens->toBe(10)
            ->cacheWriteInputTokens->toBe(100)
            ->cacheReadInputTokens->toBe(50);
    });

    test('streaming finish reason maps correctly', function (string $apiReason, $expected): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta($apiReason, 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'end_turn maps to Stop' => ['end_turn', FinishReason::Stop],
        'stop_sequence maps to Stop' => ['stop_sequence', FinishReason::Stop],
        'max_tokens maps to Length' => ['max_tokens', FinishReason::Length],
        'tool_use without tool blocks normalizes to Stop (StreamEnd still emitted)' => ['tool_use', FinishReason::Stop],
    ]);
});
