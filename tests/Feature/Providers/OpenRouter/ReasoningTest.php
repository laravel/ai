<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

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

function openRouterFollowUpAssistantMessage(): array
{
    $requests = Http::recorded();
    $messages = json_decode((string) $requests[1][0]->body(), true)['messages'];

    return collect($messages)->first(fn (array $message): bool => $message['role'] === 'assistant' && isset($message['tool_calls']));
}

test('prompt replays the reasoning details with the tool calls they produced', function (): void {
    $details = [
        ['type' => 'reasoning.summary', 'summary' => 'I need a number.', 'format' => 'openai-responses-v1', 'index' => 0],
        ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => 1],
    ];

    Http::fake(['*' => Http::sequence([
        Http::response([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-5.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_details' => $details,
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'type' => 'function',
                        'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
        fakeOpenRouterResponse('The number is 72019'),
    ])]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    expect(openRouterFollowUpAssistantMessage()['reasoning_details'])->toBe($details);
});

test('streaming replays the merged reasoning details with the tool calls they produced', function (): void {
    Http::fake(['*' => Http::sequence([
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.summary', 'summary' => 'I need ', 'format' => 'openai-responses-v1', 'index' => 0]]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.summary', 'summary' => 'a number.', 'format' => 'openai-responses-v1', 'index' => 0]]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => 1]]]),
                $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ])]);

    $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    expect(openRouterFollowUpAssistantMessage()['reasoning_details'])->toBe([
        ['type' => 'reasoning.summary', 'summary' => 'I need a number.', 'format' => 'openai-responses-v1', 'index' => 0],
        ['type' => 'reasoning.encrypted', 'data' => 'ciphertext', 'id' => 'rs_123', 'format' => 'openai-responses-v1', 'index' => 1],
    ]);
});

test('streaming keeps a signature that arrives after the reasoning text', function (): void {
    Http::fake(['*' => Http::sequence([
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'text' => 'Let me ', 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'text' => 'think.', 'signature' => null, 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                $this->chatChunk(['reasoning_details' => [['type' => 'reasoning.text', 'text' => '', 'signature' => 'sig', 'format' => 'anthropic-claude-v1', 'index' => 0]]]),
                $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ])]);

    $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    expect(openRouterFollowUpAssistantMessage()['reasoning_details'])->toBe([
        ['type' => 'reasoning.text', 'text' => 'Let me think.', 'format' => 'anthropic-claude-v1', 'index' => 0, 'signature' => 'sig'],
    ]);
});

test('a tool call without reasoning details is replayed without them', function (): void {
    Http::fake(['*' => Http::sequence([
        fakeOpenRouterToolCallResponse(),
        fakeOpenRouterResponse('The number is 72019'),
    ])]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    expect(openRouterFollowUpAssistantMessage())->not->toHaveKey('reasoning_details');
});
