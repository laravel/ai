<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('emits reasoning events while streaming', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'Hmm, let']),
                $this->chatChunk(['reasoning' => ' me think.']),
                $this->chatChunk(['content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect($events[1])->toBeInstanceOf(ReasoningStart::class)
        ->and($events[2])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe('Hmm, let')
        ->and($events[3])->toBeInstanceOf(ReasoningDelta::class)->delta->toBe(' me think.')
        ->and($events[4])->toBeInstanceOf(ReasoningEnd::class)
        ->and($events[5])->toBeInstanceOf(TextStart::class)
        ->and($events[6])->toBeInstanceOf(TextDelta::class)->delta->toBe('Hello');
});

test('replays reasoning alongside tool calls on the follow up request', function (): void {
    Http::fake(['api.groq.com/*' => Http::sequence([
        Http::response([
            'id' => 'chatcmpl-123',
            'model' => 'openai/gpt-oss-20b',
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
        Http::response([
            'id' => 'chatcmpl-124',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'The number is 72019'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5],
        ]),
    ])]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'groq');

    $messages = json_decode(Http::recorded()[1][0]->body(), true)['messages'];

    $assistantMessage = collect($messages)->first(
        fn (array $message): bool => isset($message['tool_calls'])
    );

    expect($assistantMessage['reasoning'])->toBe('I need the generator for this.')
        ->and($assistantMessage)->not->toHaveKey('reasoning_content');
});

test('does not emit reasoning events when the stream has no reasoning', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $types = array_map(fn ($event): string => $event::class, $this->collectStreamEvents());

    expect($types)->not->toContain(ReasoningStart::class)
        ->not->toContain(ReasoningDelta::class)
        ->not->toContain(ReasoningEnd::class);
});
