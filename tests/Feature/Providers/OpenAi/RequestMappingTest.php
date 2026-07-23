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
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and input', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-5.4'
            && is_array($body['input'])
            && collect($body['input'])->contains(fn ($m): bool => $m['role'] === 'user'
                && collect($m['content'])->contains(fn ($c): bool => ($c['text'] ?? '') === 'Hi there'));
    });
});

test('system instructions are sent as system message in input', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});

test('temperature, max tokens, and top_p are included when set via attributes', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_output_tokens') === 4096
            && data_get($body, 'top_p') === 0.8;
    });
});

test('temperature, max tokens, and top_p are excluded when not set', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body)
            && ! array_key_exists('top_p', $body);
    });
});

test('tools include tool choice auto', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('structured agent without Strict attribute sends strict false in text format', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('{"elements": []}')]);

    (new NestedStructuredAgent)->prompt('List elements.', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && $format['strict'] === false;
    });
});

test('request without schema excludes text format', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('request sends bearer token authorization', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'openai');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('openai');
});

test('response usage is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Hello',
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

    expect($response->structured['symbol'])->toBe('Au');
});

test('citations deduplicate by url and accumulate ranges', function () {
    Http::fake(['*' => Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Here are sources',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'Same Title',
                        'start_index' => 0,
                        'end_index' => 10,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/two',
                        'title' => 'Same Title',
                        'start_index' => 11,
                        'end_index' => 25,
                    ],
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'Same Title',
                        'start_index' => 26,
                        'end_index' => 40,
                    ],
                ],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Give me sources', provider: 'openai');

    expect($response->meta->citations)->toHaveCount(2)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/one')
        ->and($response->meta->citations[0]->startIndex)->toBe(0)
        ->and($response->meta->citations[0]->endIndex)->toBe(10)
        ->and($response->meta->citations[0]->ranges->all())->toBe([[0, 10], [26, 40]])
        ->and($response->meta->citations[1]->url)->toBe('https://example.com/two')
        ->and($response->meta->citations[1]->startIndex)->toBe(11)
        ->and($response->meta->citations[1]->ranges->all())->toBe([[11, 25]]);
});

test('citations omit span indices when not provided by the api', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => 'Sources',
                'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url' => 'https://example.com/one',
                        'title' => 'One',
                    ],
                ],
            ]],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Give me sources', provider: 'openai');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->startIndex)->toBeNull()
        ->and($response->meta->citations[0]->endIndex)->toBeNull();
});

test('required tool choice forces the model to call a tool', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('42')]);

    (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'name' => 'custom_named_tool',
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    Http::fake(['*' => fakeOpenAiResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});
