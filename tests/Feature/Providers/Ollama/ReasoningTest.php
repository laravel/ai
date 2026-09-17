<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;

test('prompt reads the thinking off the response', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'deepseek-r1:8b',
        'message' => [
            'role' => 'assistant',
            'thinking' => 'Let me think...',
            'content' => 'Hello',
        ],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 1,
        'eval_count' => 1,
    ])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'ollama');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('a response without thinking leaves the reasoning empty', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'ollama')->reasoning)->toBe('');
});

test('streaming emits reasoning events before the text', function (): void {
    Http::fake(['*' => Http::response($this->ndjsonPayload([
        ['model' => 'deepseek-r1:8b', 'message' => ['role' => 'assistant', 'thinking' => 'Let me '], 'done' => false],
        ['model' => 'deepseek-r1:8b', 'message' => ['role' => 'assistant', 'thinking' => 'think...'], 'done' => false],
        $this->chatChunk('Hello'),
        $this->chatChunk('', done: true, doneReason: 'stop', usage: ['prompt_eval_count' => 1, 'eval_count' => 1]),
    ]))]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});
