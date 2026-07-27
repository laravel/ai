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
use Tests\Feature\Providers\DeepSeek\DeepSeekHelpers;
use Tests\Fixtures\Agents\HistoricalReasoningWithoutToolCallsAgent;
use Tests\Fixtures\Agents\HistoricalToolCallWithEmptyReasoningAgent;
use Tests\Fixtures\Agents\HistoricalToolCallWithoutReasoningAgent;
use Tests\Fixtures\Agents\HistoricalToolCallWithReasoningAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

uses(DeepSeekHelpers::class);

beforeEach(function (): void {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('preserves reasoning content across tool-call loops', function (): void {
    Http::fake([
        'api.deepseek.com/*' => Http::sequence([
            Http::response([
                'id' => 'chatcmpl-deepseek-tool-123',
                'object' => 'chat.completion',
                'model' => 'deepseek-chat',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'reasoning_content' => 'Let me think. I need to call the FixedNumberGenerator.',
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'FixedNumberGenerator',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'deepseek');

    $followUpMessages = $this->requestMessages(1);
    $assistantMsg = $this->findMessage($followUpMessages, role: 'assistant', has: 'tool_calls');

    expect($assistantMsg['reasoning_content'])
        ->toBe('Let me think. I need to call the FixedNumberGenerator.')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
});

test('emits reasoning start, delta, and end events while streaming', function (): void {
    Http::fake([
        'api.deepseek.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Hmm, let']),
                $this->chatChunk(['reasoning_content' => ' me think.']),
                $this->chatChunk(['content' => 'Hello']),
                $this->chatChunk(['content' => ' world']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
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

test('emits a single reasoning block when a chunk carries both reasoning and content', function (): void {
    // DeepSeek emits a transition chunk holding the last reasoning token and the first answer token...
    Http::fake([
        'api.deepseek.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Hmm, let']),
                $this->chatChunk(['reasoning_content' => ' me think.', 'content' => 'Hello']),
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

    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningStart))->toHaveCount(1);
    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd))->toHaveCount(1);
});

test('does not close the reasoning block on an empty tool calls array', function (): void {
    Http::fake([
        'api.deepseek.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Hmm, let']),
                $this->chatChunk(['tool_calls' => []]),
                $this->chatChunk(['reasoning_content' => ' me think.']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $events = $this->collectStreamEvents();

    expect(array_filter($events, fn ($e): bool => $e instanceof ReasoningStart))->toHaveCount(1)
        ->and(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd))->toHaveCount(1)
        ->and(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta))->toHaveCount(2);
});

test('preserves reasoning content across streaming tool-call loops', function (): void {
    Http::fake([
        'api.deepseek.com/*' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'reasoning_content' => 'Thinking process...']),
                    $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                    $this->chatChunkToolCallDelta(0, '{}'),
                    $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $followUpMessages = $this->requestMessages(1);
    $assistantMsg = $this->findMessage($followUpMessages, role: 'assistant', has: 'tool_calls');

    expect($assistantMsg['reasoning_content'])->toBe('Thinking process...')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
});

test('strips reasoning from deepseek-reasoner historical messages without tool calls', function (): void {
    Http::fake([
        'api.deepseek.com/*' => Http::response([
            'id' => 'chatcmpl-reasoner-2',
            'object' => 'chat.completion',
            'model' => 'deepseek-reasoner',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'The answer is 6.'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
        ]),
    ]);

    (new HistoricalReasoningWithoutToolCallsAgent)->prompt('What is 3+3?', provider: 'deepseek', model: 'deepseek-reasoner');

    $assistantMsg = $this->findMessage($this->requestMessages(0), role: 'assistant');

    expect($assistantMsg['content'])->toBe('The answer is 8.')
        ->and($assistantMsg)->not->toHaveKey('reasoning_content')
        ->and($assistantMsg)->not->toHaveKey('tool_calls');
});

test('defaults reasoning content to empty string when historical reasoning is empty', function (): void {
    Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Here you go.')]);

    (new HistoricalToolCallWithEmptyReasoningAgent)->prompt('tell me more', provider: 'deepseek');

    $messages = $this->requestMessages(0);
    $assistantMsg = $this->findMessage($messages, role: 'assistant');

    expect($assistantMsg['content'])->toBe('Found the products.')
        ->and($assistantMsg['reasoning_content'])->toBe('')
        ->and($assistantMsg['tool_calls'])->toHaveCount(1)
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('SearchProducts');

    expect($this->filterMessages($messages, 'tool'))->toHaveCount(1);
});

test('defaults reasoning content to empty string when historical reasoning is missing', function (): void {
    Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Sure, here is the info.')]);

    (new HistoricalToolCallWithoutReasoningAgent)->prompt('tell me more', provider: 'deepseek');

    $messages = $this->requestMessages(0);
    $assistantMsg = $this->findMessage($messages, role: 'assistant');

    expect($assistantMsg['content'])->toBe('Found the products.')
        ->and($assistantMsg['reasoning_content'])->toBe('')
        ->and($assistantMsg['tool_calls'])->toHaveCount(1)
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('SearchProducts');

    expect($this->filterMessages($messages, 'tool'))->toHaveCount(1);
});

test('preserves tool calls when historical reasoning content is present', function (): void {
    Http::fake(['api.deepseek.com/*' => fakeDeepSeekResponse('Here you go.')]);

    (new HistoricalToolCallWithReasoningAgent)->prompt('tell me more', provider: 'deepseek');

    $messages = $this->requestMessages(0);
    $assistantMsg = $this->findMessage($messages, role: 'assistant');

    expect($assistantMsg['content'])->toBe('Found the products.')
        ->and($assistantMsg['reasoning_content'])->toBe('I need to search for lampu products.')
        ->and($assistantMsg['tool_calls'])->toHaveCount(1)
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('SearchProducts');

    expect($this->filterMessages($messages, 'tool'))->toHaveCount(1);
});
