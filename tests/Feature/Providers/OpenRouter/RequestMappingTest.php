<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\NestedStructuredAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openrouter', model: 'anthropic/claude-sonnet-4.6');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'anthropic/claude-sonnet-4.6'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'function' => ['name' => 'custom_named_tool'],
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output includes json schema response format', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'response_format');

        return $format['type'] === 'json_schema'
            && isset($format['json_schema']['name'])
            && isset($format['json_schema']['schema'])
            && $format['json_schema']['strict'] === true;
    });
});

test('structured output without Strict attribute sends strict false in response format', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('{"elements": []}')]);

    (new NestedStructuredAgent)->prompt('List elements.', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $format = data_get(json_decode($request->body(), true), 'response_format');

        return $format['type'] === 'json_schema'
            && $format['json_schema']['strict'] === false;
    });
});

test('request without schema excludes response format', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('streaming request includes stream options', function (): void {
    Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'openrouter');

    foreach ($stream as $event) {
        //
    }

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && data_get($body, 'stream_options.include_usage') === true;
    });
});

test('request sends bearer token authorization', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('request sends http referer and x openrouter title headers when configured', function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
        'http_referer' => 'https://example.com',
        'x_title' => 'My App',
    ]]);

    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('HTTP-Referer', 'https://example.com')
        && $request->hasHeader('X-OpenRouter-Title', 'My App'));
});

test('response text is correctly parsed', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'openrouter');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('openrouter');
});

test('response usage is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('response usage includes cache and reasoning tokens', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_tokens_details' => [
                'cached_tokens' => 20,
                'cache_write_tokens' => 80,
            ],
            'completion_tokens_details' => [
                'reasoning_tokens' => 10,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openrouter');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->cacheReadInputTokens)->toBe(20)
        ->and($response->usage->cacheWriteInputTokens)->toBe(80)
        ->and($response->usage->reasoningTokens)->toBe(10);
});

test('structured response is correctly parsed', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openrouter');

    expect($response->structured['symbol'])->toBe('Au');
});

test('web search citations are extracted from message annotations', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Paris is the capital of France.',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/paris',
                            'title' => 'Paris - Wikipedia',
                            'start_index' => 0,
                            'end_index' => 30,
                        ],
                    ],
                    [
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/france',
                            'title' => 'France - Wikipedia',
                            'start_index' => 31,
                            'end_index' => 50,
                        ],
                    ],
                ],
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $response = agent()->prompt('What is the capital of France?', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(2)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/paris')
        ->and($response->meta->citations[0]->title)->toBe('Paris - Wikipedia')
        ->and($response->meta->citations[0]->startIndex)->toBe(0)
        ->and($response->meta->citations[0]->endIndex)->toBe(30)
        ->and($response->meta->citations[1]->url)->toBe('https://example.com/france')
        ->and($response->meta->citations[1]->title)->toBe('France - Wikipedia')
        ->and($response->meta->citations[1]->startIndex)->toBe(31)
        ->and($response->meta->citations[1]->endIndex)->toBe(50);
});

test('web search citations omit span indices when not provided', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'anthropic/claude-sonnet-4.6',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => 'Some answer.',
                'annotations' => [[
                    'type' => 'url_citation',
                    'url_citation' => [
                        'url' => 'https://example.com/source',
                        'title' => 'Source',
                    ],
                ]],
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
    ])]);

    $response = agent()->prompt('Question', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/source')
        ->and($response->meta->citations[0]->startIndex)->toBeNull()
        ->and($response->meta->citations[0]->endIndex)->toBeNull();
});

test('response with no annotations has empty citations collection', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    $response = agent()->prompt('Hi', provider: 'openrouter');

    expect($response->meta->citations)->toHaveCount(0);
});
