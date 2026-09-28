<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('streaming emits text events', function (): void {
    Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hello', ' world'))]);

    $events = $this->collectStreamEvents();

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and($events[3])->toBeInstanceOf(TextDelta::class)->delta->toBe(' world')
        ->and($events[4])->toBeInstanceOf(TextEnd::class)
        ->and($events[5])->toBeInstanceOf(StreamEnd::class)
        ->and($events)->toHaveCount(6);
});

test('streaming captures usage and finish reason', function (): void {
    Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

    $streamEnd = collect($this->collectStreamEvents())->last();

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnd->usage->inputTokens)->toBe(20)
        ->and($streamEnd->usage->outputTokens)->toBe(10);
});

test('streaming accumulates tool call arguments and runs the tool', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeStreamResponse($this->streamToolCallEvents('FixedNumberGenerator', 'get_number_ejj5xe67w3e1', ['{"', 'city', '":', ' "', 'Paris', '"}'])),
            $this->fakeStreamResponse($this->streamTextEvents('The number is 72019')),
        ]),
    ]);

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $toolCalls = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
    $toolResults = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));
    $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

    expect($toolCalls)->toHaveCount(1)
        ->and($toolCalls[0]->toolCall->id)->toBe('get_number_ejj5xe67w3e1')
        ->and($toolCalls[0]->toolCall->name)->toBe('FixedNumberGenerator')
        ->and($toolCalls[0]->toolCall->arguments)->toBe(['city' => 'Paris'])
        ->and($toolResults)->toHaveCount(1)
        ->and($streamEnds)->toHaveCount(1)
        ->and($streamEnds[0]->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds[0]->usage->inputTokens)->toBe(30)
        ->and($streamEnds[0]->usage->outputTokens)->toBe(15);

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->firstWhere('role', 'tool')['tool_call_id'])->toBe('get_number_ejj5xe67w3e1');
});

test('streaming ignores tool plan deltas', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeStreamResponse($this->streamToolCallEvents()),
            $this->fakeStreamResponse($this->streamTextEvents('Done')),
        ]),
    ]);

    $deltas = collect($this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent))
        ->filter(fn ($e): bool => $e instanceof TextDelta)
        ->map(fn (TextDelta $e): string => $e->delta)
        ->values()
        ->all();

    expect($deltas)->toBe(['Done']);
});

test('streaming emits reasoning events before the text', function (): void {
    Http::fake(['*' => $this->fakeStreamResponse([
        ['type' => 'message-start', 'id' => 'msg_1', 'delta' => ['message' => ['role' => 'assistant']]],
        ['type' => 'content-start', 'index' => 0, 'delta' => ['message' => ['content' => ['type' => 'thinking', 'thinking' => '']]]],
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'Let me ']]]],
        ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => 'think...']]]],
        ['type' => 'content-end', 'index' => 0],
        ['type' => 'content-start', 'index' => 1, 'delta' => ['message' => ['content' => ['type' => 'text', 'text' => '']]]],
        ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Hello']]]],
        ['type' => 'content-end', 'index' => 1],
        ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE', 'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 5]]]],
    ])]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello');
});
