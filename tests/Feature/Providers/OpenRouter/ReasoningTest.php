<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
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

test('a tool call follow up replays the reasoning details unchanged', function (): void {
    $details = [
        ['type' => 'reasoning.text', 'id' => 'rs_1', 'format' => 'anthropic-claude-v1', 'index' => 0, 'text' => 'I should call the tool.', 'signature' => 'sig'],
    ];

    Http::fake(['*' => Http::sequence([
        Http::response([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
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

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'openrouter');

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->firstWhere('role', 'assistant')['reasoning_details'])->toBe($details);
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

test('a streamed tool call follow up replays the reasoning details it accumulated', function (): void {
    Http::fake(['*' => Http::sequence([
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'reasoning' => 'I should ', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'id' => 'rs_1', 'text' => 'I should ']]]),
                $this->chatChunk(['reasoning' => 'call the tool.', 'reasoning_details' => [['type' => 'reasoning.text', 'index' => 0, 'text' => 'call the tool.', 'signature' => 'sig']]]),
                $this->chatChunkToolCallStart(0, 'call_123', 'FixedNumberGenerator'),
                $this->chatChunkToolCallDelta(0, '{}'),
                $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
        Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 1, 'completion_tokens' => 1]),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ])]);

    foreach (agent(tools: [new FixedNumberGenerator])->stream('Generate a random number', provider: 'openrouter') as $event) {
        //
    }

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->firstWhere('role', 'assistant')['reasoning_details'])->toBe([
        ['type' => 'reasoning.text', 'index' => 0, 'id' => 'rs_1', 'text' => 'I should call the tool.', 'signature' => 'sig'],
    ]);
});

test('continuing a conversation replays the reasoning details of the completed turn', function (): void {
    Config::set('ai.conversations.generate_title', false);

    $details = [
        ['type' => 'reasoning.text', 'id' => 'rs_1', 'format' => 'anthropic-claude-v1', 'index' => 0, 'text' => 'They asked for a greeting.', 'signature' => 'sig'],
    ];

    Http::fake(['*' => Http::sequence([
        fakeOpenRouterReasonedResponse(['reasoning_details' => $details]),
        fakeOpenRouterResponse('Hello again'),
    ])]);

    $user = (object) ['id' => 1];

    $first = (new RememberingAssistantAgent)->forUser($user)->prompt('Hi', provider: 'openrouter');

    (new RememberingAssistantAgent)
        ->continue($first->conversationId, $user)
        ->prompt('Hi again', provider: 'openrouter');

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->firstWhere('role', 'assistant')['reasoning_details'])->toBe($details);
});
