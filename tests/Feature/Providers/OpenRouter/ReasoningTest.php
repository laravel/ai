<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenRouterReasonedResponse(array $message): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello', ...$message],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

test('prompt reads the plaintext reasoning off the response', function (): void {
    Http::fake(['*' => fakeOpenRouterReasonedResponse(['reasoning' => 'Let me think...'])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'openrouter');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

test('prompt falls back to the reasoning details when no plaintext reasoning is sent', function (): void {
    Http::fake(['*' => fakeOpenRouterReasonedResponse(['reasoning_details' => [
        ['type' => 'reasoning.text', 'text' => 'First.', 'index' => 0],
        ['type' => 'reasoning.summary', 'summary' => 'Second.', 'index' => 1],
        ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'index' => 2],
    ]])]);

    expect((new AssistantAgent)->prompt('Hi', provider: 'openrouter')->reasoning)->toBe("First.\n\nSecond.");
});

test('streaming emits reasoning events', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
            $this->chatChunk(['reasoning' => 'think...', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...', 'signature' => 'sig']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello')
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...');
});

test('streamed reasoning details drive the reasoning events when no plaintext reasoning is sent', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'Let me ']]]),
            $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'think...']]]),
            $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.summary', 'index' => 1, 'summary' => 'I decided.']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Let me ')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('think...')
        ->and($events[4])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('I decided.')
        ->and($events[5])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[6])->toBeInstanceOf(TextStart::class)
        ->and(ReasoningDelta::combine($events))->toBe('Let me think...I decided.');
});

test('an encrypted reasoning detail drives no reasoning events', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.encrypted', 'index' => 0, 'data' => 'ciphertext']]]),
            $this->chatChunk(['content' => 'Hello']),
            $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
        ]),
        status: 200,
        headers: ['Content-Type' => 'text/event-stream'],
    )]);

    $events = $this->collectStreamEvents();

    expect(collect($events)->whereInstanceOf(ReasoningStart::class))->toBeEmpty()
        ->and(ReasoningDelta::combine($events))->toBe('');
});
