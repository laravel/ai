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
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

function geminiStream(array $events): array
{
    return [
        'body' => test()->ssePayload($events),
        'headers' => ['Content-Type' => 'text/event-stream'],
    ];
}

function geminiStreamResponse(array $events)
{
    return Http::response(
        body: test()->ssePayload($events),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    );
}

describe('text streaming', function (): void {
    test('streaming emits provider tool events for code execution steps', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                $this->stepDelta(0, 'text', 'print(1)'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'code_execution_result', 'id' => 'ce_1']),
                $this->stepDelta(1, 'text', '1'),
                $this->stepStop(1),
                $this->stepStart(2, ['type' => 'model_output']),
                $this->stepDelta(2, 'text', 'The answer is 1.'),
                $this->stepStop(2),
                $this->interactionCompleted(),
            ]),
        ]);

        $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof ProviderToolEvent));

        expect(array_map(fn (ProviderToolEvent $e): string => $e->status, $providerEvents))->toBe(['completed', 'result_received'])
            ->and($providerEvents[0])->type->toBe('code_execution')->provider->toBe('gemini')
            ->and($providerEvents[1]->type)->toBe('code_execution');
    });

    test('streaming keeps the step payload gemini only sends as deltas', function (): void {
        $rawDelta = fn (int $index, array $delta): array => ['event_type' => 'step.delta', 'index' => $index, 'delta' => $delta];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'thought']),
                    $rawDelta(0, ['type' => 'thought_signature', 'signature' => 'sig_abc']),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                    $rawDelta(1, ['type' => 'code_execution_call', 'arguments' => ['language' => 'python', 'code' => 'print(1)']]),
                    $this->stepStop(1),
                    $this->stepStart(2, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []]),
                    $this->stepStop(2),
                    $this->interactionCompleted(),
                ]))
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator]));

        $providerEvent = array_values(array_filter($events, fn ($e): bool => $e instanceof ProviderToolEvent))[0];

        [$request] = Http::recorded()[1];

        expect($providerEvent->data['arguments'])->toBe(['language' => 'python', 'code' => 'print(1)'])
            ->and($request->data()['input'][1])->toBe(['type' => 'thought', 'signature' => 'sig_abc'])
            ->and($request->body())->toContain('"name":"FixedNumberGenerator","arguments":{}');
    });

    test('streaming replays provider tool steps when continuing after a function call', function (): void {
        $completedSteps = [
            ['type' => 'code_execution_call', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => 'print(1)']]],
            ['type' => 'code_execution_result', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => '1']]],
            ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []],
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted($completedSteps),
                ]))
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator]));

        Http::assertSentCount(2);

        $input = Http::recorded()[1][0]->data()['input'];

        expect(array_column($input, 'type'))->toBe([
            'user_input', 'code_execution_call', 'code_execution_result', 'function_call', 'function_result',
        ]);
    });

    test('streaming emits citation events for annotations on the completed interaction', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Spain won Euro 2024.'),
                $this->stepStop(0),
                $this->interactionCompleted([
                    $this->modelOutput('Spain won Euro 2024.', [
                        ['uri' => 'https://example.com/euro', 'title' => 'Euro 2024'],
                        ['uri' => 'https://example.com/spain', 'title' => 'Spain Wins'],
                    ]),
                ]),
            ]),
        ]);

        $citations = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof CitationEvent));

        expect($citations)->toHaveCount(2)
            ->and($citations[0]->citation->url)->toBe('https://example.com/euro')
            ->and($citations[1]->citation->url)->toBe('https://example.com/spain');
    });

    test('streaming emits text events', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Hello'),
                $this->stepDelta(0, 'text', ' world'),
                $this->stepStop(0),
                $this->interactionCompleted(),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        expect($events[0])->toBeInstanceOf(StreamStart::class)
            ->and($events[1])->toBeInstanceOf(TextStart::class)
            ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
            ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
            ->and($events[count($events) - 2])->toBeInstanceOf(TextEnd::class)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });

    test('streaming posts to the interactions endpoint with sse and the stream flag', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted(),
            ]),
        ]);

        $this->collectStreamEvents();

        expect(sentRequest()->url())->toContain('interactions?alt=sse')
            ->and(sentRequest()->data())->toMatchArray(['stream' => true]);
    });
});

describe('tool calls', function (): void {
    test('streaming handles tool calls', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted([$this->functionCallStep('FixedNumberGenerator', [], 'call_1')]),
                ]))
                ->push(...geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

        expect($toolCallEvents)->not->toBeEmpty()
            ->and($toolCallEvents[0]->toolCall->name)->toBe('FixedNumberGenerator')
            ->and($toolCallEvents[0]->toolCall->id)->toBe('call_1');
    });

    test('streaming accumulates function call arguments from argument deltas', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->argumentsDelta(0, '{"see'),
                    $this->argumentsDelta(0, 'd": 7}'),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]))
                ->push(...geminiStream([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCall = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent))[0];

        expect($toolCall->toolCall->arguments)->toBe(['seed' => 7]);
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted(
                        [$this->functionCallStep('FixedNumberGenerator', [], 'call_1')],
                        ['total_input_tokens' => 10, 'total_output_tokens' => 5],
                    ),
                ]))
                ->push(...geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted([], ['total_input_tokens' => 20, 'total_output_tokens' => 10]),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

        expect($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
            ->and($streamEnds[0]->usage)
            ->inputTokens->toBe(30)
            ->outputTokens->toBe(15);
    });

    test('streaming replays thought steps verbatim into the tool call continuation', function (): void {
        $thought = [
            'type' => 'thought',
            'summary' => [['type' => 'text', 'text' => 'thinking...']],
            'thought_signature' => 'sig_stream_555',
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'thought']),
                    $this->stepDelta(0, 'thought_summary', 'thinking...'),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(1),
                    $this->interactionCompleted([
                        $thought,
                        $this->functionCallStep('FixedNumberGenerator', [], 'call_1'),
                    ]),
                ]))
                ->push(...geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $replayed = array_values(array_filter(
            Http::recorded()[1][0]->data()['input'],
            fn (array $step): bool => $step['type'] === 'thought',
        ));

        expect($replayed)->toBe([$thought]);
    });

    test('streaming falls back to the accumulated steps when the completed event carries none', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]))
                ->push(...geminiStream([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCalls = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0]->toolCall->id)->toBe('call_1');
    });
});

describe('thinking blocks', function (): void {
    test('streaming handles thought summary deltas', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepStart(0, ['type' => 'thought']),
                $this->stepDelta(0, 'thought_summary', 'Let me think...'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'model_output']),
                $this->stepDelta(1, 'text', 'Answer'),
                $this->stepStop(1),
                $this->interactionCompleted(),
            ]),
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
});

describe('error handling', function (): void {
    test('streaming error event stops stream', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                ['event_type' => 'error', 'error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
            ]),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        expect($error)->toBeInstanceOf(Error::class)->type->toBe('overloaded')->message->toBe('Server overloaded');
    });
});

describe('usage tracking', function (): void {
    test('streaming captures usage from the completed event', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([], [
                    'total_input_tokens' => 42,
                    'total_output_tokens' => 10,
                    'total_cached_tokens' => 5,
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->usage)
            ->inputTokens->toBe(42)
            ->outputTokens->toBe(10)
            ->cacheReadInputTokens->toBe(5);
    });

    test('streaming finish reason maps from the interaction status', function (string $status, FinishReason $expected): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([], [], $status),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        expect($streamEnd->reason)->toBe($expected->value);
    })->with([
        'completed maps to Stop' => ['completed', FinishReason::Stop],
        'incomplete maps to Length' => ['incomplete', FinishReason::Length],
    ]);
});
