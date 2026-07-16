<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'groq', model: 'llama-3.3-70b-versatile');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'llama-3.3-70b-versatile'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_completion_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('tools include tool choice auto', function (): void {
    Http::fake(['*' => fakeGroqResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && $body['tools'] !== [];
    });
});

test('request without tools excludes tool fields', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice forces the model to call a tool', function (): void {
    Http::fake(['*' => fakeGroqResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('required tool choice can be set via attribute', function (): void {
    Http::fake(['*' => fakeGroqResponse('42')]);

    (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'required');
});

test('named tool choice forces a specific function', function (): void {
    Http::fake(['*' => fakeGroqResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === [
        'type' => 'function',
        'function' => ['name' => 'custom_named_tool'],
    ]);
});

test('none tool choice prevents tool calls', function (): void {
    Http::fake(['*' => fakeGroqResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'groq');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'none');
});

test('structured output includes json schema response format', function (): void {
    Http::fake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'response_format');

        return $format['type'] === 'json_schema'
            && isset($format['json_schema']['name'])
            && isset($format['json_schema']['schema'])
            && $format['json_schema']['strict'] === true;
    });
});

test('request without schema excludes response format', function (): void {
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('schema combined with tools omits response format but keeps schema instructions', function (): void {
    Http::fake(['*' => fakeGroqResponse('{"number": 42}')]);

    agent(
        tools: [new RandomNumberGenerator],
        schema: fn ($s): array => ['number' => $s->integer()->required()],
    )->prompt('Give me a number', provider: 'groq');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return ! array_key_exists('response_format', $body)
            && is_array($body['tools'])
            && $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'JSON object that strictly adheres');
    });
});

test('streaming request includes stream options', function (): void {
    Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'groq');

    // Consume the stream
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
    Http::fake(['*' => fakeGroqResponse('Hello')]);

    agent()->prompt('Hello', provider: 'groq');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('response text is correctly parsed', function (): void {
    Http::fake(['*' => fakeGroqResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'groq');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('groq');
});

test('response usage is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
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

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('response usage includes reasoning tokens', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-r1-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-r1-distill-llama-70b',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'The answer is 4.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'completion_tokens_details' => [
                'reasoning_tokens' => 20,
            ],
        ],
    ])]);

    $response = agent()->prompt('What is 2+2?', provider: 'groq', model: 'deepseek-r1-distill-llama-70b');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->reasoningTokens)->toBe(20);
});

test('response usage includes cached prompt tokens', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-cache-1',
        'object' => 'chat.completion',
        'model' => 'llama-3.3-70b-versatile',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello.'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 4641,
            'completion_tokens' => 1817,
            'total_tokens' => 6458,
            'prompt_tokens_details' => [
                'cached_tokens' => 4608,
            ],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'groq');

    expect($response->usage->promptTokens)->toBe(4641)
        ->and($response->usage->completionTokens)->toBe(1817)
        ->and($response->usage->cacheReadInputTokens)->toBe(4608);
});

test('structured response is correctly parsed', function (): void {
    Http::fake(['*' => fakeGroqResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'groq');

    expect($response->structured['symbol'])->toBe('Au');
});
