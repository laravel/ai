<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Fixtures\Agents\HistoricalForeignBlocksToolCallAgent;
use Tests\Fixtures\Agents\HistoricalReasoningWithoutToolCallsAgent;
use Tests\Fixtures\Agents\RememberingApprovableAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('emits reasoning start, delta, and end events while streaming', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Hmm, let']),
            $this->chatChunk(['reasoning' => ' me think.']),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunk(['content' => ' world']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Hmm, let')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe(' me think.')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[7])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[8])->toBeInstanceOf(TextEnd::class)
        ->and($events[9])->toBeInstanceOf(StreamEnd::class);
});

test('emits reasoning events from the reasoning_content field', function (): void {
    // OpenAI-compatible upstreams (LiteLLM, vLLM) stream reasoning under `reasoning_content`...
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Thinking it through.']),
            $this->chatChunk(['content' => 'Answer']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    $reasoningDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta));

    expect($reasoningDeltas)->toHaveCount(1)
        ->and($reasoningDeltas[0]->delta)->toBe('Thinking it through.');
});

test('closes the reasoning block before emitting tool calls', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'I should call the generator.']),
                $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ])),
            Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 5]),
            ])),
        ]),
    ]);

    $events = [];
    foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
        $events[] = $event;
    }

    $reasoningEndIndex = array_key_first(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd));
    $toolCallIndex = array_key_first(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

    expect($reasoningEndIndex)->not->toBeNull()
        ->and($toolCallIndex)->not->toBeNull()
        ->and($reasoningEndIndex)->toBeLessThan($toolCallIndex);
});

test('closes a trailing reasoning block when the stream has no answer text', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Only thinking, no answer.']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();
    $types = array_map(fn ($e): string => $e::class, $events);

    expect($types)->toContain(ReasoningStart::class)
        ->toContain(ReasoningDelta::class)
        ->toContain(ReasoningEnd::class)
        ->not->toContain(TextStart::class);

    $reasoningEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd));
    expect($reasoningEnd)->toHaveCount(1);
});

test('emits a single reasoning block when a chunk carries both reasoning and content', function (): void {
    // Some upstreams emit a transition chunk holding the last reasoning token and the first answer token...
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Hmm, let']),
            $this->chatChunk(['reasoning' => ' me think.', 'content' => 'Hello']),
            $this->chatChunk(['content' => ' world']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Hmm, let')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe(' me think.')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello');

    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningStart))->toHaveCount(1);
    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd))->toHaveCount(1);
});

test('does not close the reasoning block on an empty tool calls array', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Hmm, let']),
            $this->chatChunk(['tool_calls' => []]),
            $this->chatChunk(['reasoning' => ' me think.']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningStart))->toHaveCount(1)
        ->and(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd))->toHaveCount(1)
        ->and(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta))->toHaveCount(2);
});

test('captures streamed reasoning on the paused turn state', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'I should call the generator.']),
            $this->chatChunkToolCallStart(0, 'call_1', 'ApprovableNumberGenerator'),
            $this->chatChunkToolCallDelta(0, '{}'),
            $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $paused = null;

    (new RememberingApprovableAgent)
        ->forUser((object) ['id' => 1])
        ->stream('Generate a number', provider: 'openrouter')
        ->then(function ($response) use (&$paused): void {
            $paused = $response;
        })
        ->each(fn (): bool => true);

    expect($paused->hasPendingApprovals())->toBeTrue()
        ->and($paused->pausedProviderContentBlocks())
        ->toBe(['reasoning_content' => 'I should call the generator.']);
});

test('captures reasoning from a non-streamed response on the paused turn state', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'reasoning' => 'I should call the generator.',
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'ApprovableNumberGenerator', 'arguments' => '{}'],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = (new RememberingApprovableAgent)
        ->forUser((object) ['id' => 1])
        ->prompt('Generate a number', provider: 'openrouter');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pausedProviderContentBlocks())
        ->toBe(['reasoning_content' => 'I should call the generator.']);
});

test('emits reasoning events from the reasoning_details field', function (): void {
    // Structured reasoning models only expose the thinking text through `reasoning_details`...
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [[
                'type' => 'reasoning.text',
                'text' => 'Step by step, then.',
                'signature' => null,
                'id' => 'reasoning-text-1',
                'format' => 'anthropic-claude-v1',
                'index' => 0,
            ]]]),
            $this->chatChunk(['content' => 'Answer']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    $reasoningDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta));

    expect($reasoningDeltas)->toHaveCount(1)
        ->and($reasoningDeltas[0]->delta)->toBe('Step by step, then.');
});

test('ignores encrypted reasoning details that carry no readable text', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [[
                'type' => 'reasoning.encrypted',
                'data' => 'gAAAAAB...',
                'id' => 'reasoning-encrypted-1',
                'format' => 'anthropic-claude-v1',
            ]]]),
            $this->chatChunk(['content' => 'Answer']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $types = array_map(fn ($e): string => $e::class, $this->collectStreamEvents());

    expect($types)->not->toContain(ReasoningStart::class)
        ->not->toContain(ReasoningDelta::class)
        ->not->toContain(ReasoningEnd::class);
});

test('counts reasoning once when a chunk carries both plain text and reasoning details', function (): void {
    // OpenRouter mirrors the same thinking text into both fields, so only one may be read...
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk([
                'role' => 'assistant',
                'reasoning' => 'Thinking it through.',
                'reasoning_details' => [[
                    'type' => 'reasoning.text',
                    'text' => 'Thinking it through.',
                    'format' => 'anthropic-claude-v1',
                    'index' => 0,
                ]],
            ]),
            $this->chatChunk(['reasoning_details' => [[
                'type' => 'reasoning.text',
                'text' => ' Nearly there.',
                'format' => 'anthropic-claude-v1',
                'index' => 0,
            ]]]),
            $this->chatChunk(['content' => 'Answer']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
        ])),
    ]);

    $events = $this->collectStreamEvents();

    $reasoning = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta));

    expect($reasoning)->toHaveCount(1)
        ->and($reasoning[0]->delta)->toBe('Thinking it through.');
});

test('ignores another provider reasoning block shape when replaying', function (): void {
    // Anthropic and Gemini store a list of raw blocks rather than a keyed reasoning string...
    Http::fake(['*' => fakeOpenRouterResponse('Sure.')]);

    (new HistoricalForeignBlocksToolCallAgent)->prompt('And again?', provider: 'openrouter');

    $assistantMessage = $this->findMessage($this->requestMessages(0), role: 'assistant', has: 'tool_calls');

    expect($assistantMessage)->not->toBeNull()
        ->and($assistantMessage)->not->toHaveKey('reasoning_content');
});

test('replays reasoning alongside tool calls on the follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response([
                'id' => 'chatcmpl-123',
                'object' => 'chat.completion',
                'model' => 'anthropic/claude-sonnet-4.6',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'reasoning' => 'I need the generator for this.',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    $assistantMessage = $this->findMessage($this->requestMessages(1), role: 'assistant', has: 'tool_calls');

    expect($assistantMessage['reasoning_content'])->toBe('I need the generator for this.')
        ->and($assistantMessage['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
});

test('replays streamed reasoning alongside tool calls on the follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'I need the generator for this.']),
                $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ])),
            Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 5]),
            ])),
        ]),
    ]);

    foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
        //
    }

    $assistantMessage = $this->findMessage($this->requestMessages(1), role: 'assistant', has: 'tool_calls');

    expect($assistantMessage['reasoning_content'])->toBe('I need the generator for this.');
});

test('omits reasoning from historical assistant messages without tool calls', function (): void {
    // Reasoning is only replayed to justify pending tool calls, never to pad finished turns...
    Http::fake(['*' => fakeOpenRouterResponse('The answer is 6.')]);

    (new HistoricalReasoningWithoutToolCallsAgent)->prompt('What is 3+3?', provider: 'openrouter');

    $assistantMessage = $this->findMessage($this->requestMessages(0), role: 'assistant');

    expect($assistantMessage['content'])->toBe('The answer is 8.')
        ->and($assistantMessage)->not->toHaveKey('reasoning_content');
});

test('does not emit reasoning events when the stream has no reasoning', function (): void {
    Http::fake([
        '*' => Http::response($this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
        ])),
    ]);

    $events = $this->collectStreamEvents();
    $types = array_map(fn ($e): string => $e::class, $events);

    expect($types)->not->toContain(ReasoningStart::class)
        ->not->toContain(ReasoningDelta::class)
        ->not->toContain(ReasoningEnd::class);
});
