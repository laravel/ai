<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Feature\Providers\DeepSeek\DeepSeekHelpers;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

uses(DeepSeekHelpers::class);

beforeEach(function () {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('reasoning content is preserved in non-streaming response and replayed in tool call loop', function () {
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
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
            fakeDeepSeekResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'deepseek',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $assistantMsg = collect($followUpMessages)->first(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls']));

    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg['reasoning_content'])->toBe('Let me think. I need to call the FixedNumberGenerator.')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
});

test('streaming emits reasoning start, delta, and end events', function () {
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

test('streaming reasoning content is replayed in streaming tool call loops', function () {
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

    $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $assistantMsg = collect($followUpMessages)->last(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls']));

    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg['reasoning_content'])->toBe('Thinking process...')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
});

test('historical assistant messages with tool_calls but missing reasoning_content are gracefully degraded', function () {
    Http::fake([
        'api.deepseek.com/*' => fakeDeepSeekResponse('Sure, here is the info.'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): Stringable|string
        {
            return 'You are a helpful assistant.';
        }

        public function provider(): string
        {
            return 'deepseek';
        }

        public function messages(): iterable
        {
            return [
                new UserMessage('check stock lampu'),
                // Historical assistant message WITH tool_calls but WITHOUT reasoning_content
                new AssistantMessage(
                    'Found the products.',
                    collect([
                        new ToolCall('call_old', 'SearchProducts', ['keyword' => 'lampu'], 'call_old'),
                    ]),
                    [] // No reasoning_content!
                ),
                new ToolResultMessage(collect([
                    new ToolResult('call_old', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_old'),
                ])),
                new UserMessage('tell me more'),
            ];
        }
    };

    $agent->prompt('tell me more', provider: 'deepseek');

    $recorded = Http::recorded();
    $body = json_decode($recorded[0][0]->body(), true);
    $messages = $body['messages'];

    // The assistant message should have its tool_calls stripped (content preserved)
    $assistantMsgs = collect($messages)->filter(fn ($m) => $m['role'] === 'assistant');
    expect($assistantMsgs)->toHaveCount(1);

    $assistantMsg = $assistantMsgs->first();
    expect($assistantMsg['content'])->toBe('Found the products.')
        ->and($assistantMsg)->not->toHaveKey('tool_calls')
        ->and($assistantMsg)->not->toHaveKey('reasoning_content');

    // The tool result message should have been skipped entirely
    $toolMsgs = collect($messages)->filter(fn ($m) => $m['role'] === 'tool');
    expect($toolMsgs)->toHaveCount(0);

    // User messages should still be present (2 from history + 1 from prompt)
    $userMsgs = collect($messages)->filter(fn ($m) => $m['role'] === 'user');
    expect($userMsgs)->toHaveCount(3);
});

test('historical assistant messages with tool_calls AND reasoning_content are preserved intact', function () {
    Http::fake([
        'api.deepseek.com/*' => fakeDeepSeekResponse('Here you go.'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): Stringable|string
        {
            return 'You are a helpful assistant.';
        }

        public function provider(): string
        {
            return 'deepseek';
        }

        public function messages(): iterable
        {
            return [
                new UserMessage('check stock lampu'),
                // Historical assistant message WITH tool_calls AND reasoning_content
                new AssistantMessage(
                    'Found the products.',
                    collect([
                        new ToolCall('call_old', 'SearchProducts', ['keyword' => 'lampu'], 'call_old'),
                    ]),
                    ['reasoning_content' => 'I need to search for lampu products.']
                ),
                new ToolResultMessage(collect([
                    new ToolResult('call_old', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_old'),
                ])),
                new UserMessage('tell me more'),
            ];
        }
    };

    $agent->prompt('tell me more', provider: 'deepseek');

    $recorded = Http::recorded();
    $body = json_decode($recorded[0][0]->body(), true);
    $messages = $body['messages'];

    // The assistant message should be preserved intact
    $assistantMsg = collect($messages)->first(fn ($m) => $m['role'] === 'assistant');
    expect($assistantMsg['content'])->toBe('Found the products.')
        ->and($assistantMsg['reasoning_content'])->toBe('I need to search for lampu products.')
        ->and($assistantMsg['tool_calls'])->toHaveCount(1)
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('SearchProducts');

    // The tool result message should be present
    $toolMsgs = collect($messages)->filter(fn ($m) => $m['role'] === 'tool');
    expect($toolMsgs)->toHaveCount(1);
});
