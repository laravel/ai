<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('prompt reads the parsed reasoning off the response', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Hello',
                'reasoning' => 'Let me think...',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ])]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'groq');

    expect($response->reasoning)->toBe('Let me think...')
        ->and($response->text)->toBe('Hello');
});

// Groq rejects reasoning_format on non-reasoning and GPT-OSS models, so the caller opts in per model...
test('no reasoning format is sent unless the caller asks for one', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    (new AssistantAgent)->prompt('Hi', provider: 'groq');

    Http::assertSent(fn ($request): bool => ! array_key_exists('reasoning_format', $request->data()));
});

test('the reasoning format can be set with provider options', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning_format' => 'hidden'];
        }
    };

    $agent->prompt('Hi', provider: 'groq');

    Http::assertSent(fn ($request): bool => $request->data()['reasoning_format'] === 'hidden');
});

test('streaming emits reasoning events before the text', function (): void {
    Http::fake(['*' => Http::response(
        body: $this->ssePayload([
            $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Let me ']),
            $this->chatChunk(['reasoning' => 'think...']),
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
