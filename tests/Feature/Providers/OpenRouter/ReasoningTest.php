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
    // OpenAI-compatible upstreams (LiteLLM, vLLM) stream reasoning under
    // `reasoning_content` instead of OpenRouter's native `reasoning`.
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
