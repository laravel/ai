<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.litellm' => [
        ...config('ai.providers.litellm'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'litellm', model: 'anthropic/claude-3.5-sonnet');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'anthropic/claude-3.5-sonnet'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    agent()->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeLiteLLMResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    agent()->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema response format', function () {
    Http::fake(['*' => fakeLiteLLMResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'response_format');

        return $format['type'] === 'json_schema'
            && isset($format['json_schema']['name'])
            && isset($format['json_schema']['schema'])
            && $format['json_schema']['strict'] === true;
    });
});

test('request without schema excludes response format', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    agent()->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('streaming request includes stream options', function () {
    Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'litellm');

    foreach ($stream as $event) {
        //
    }

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && data_get($body, 'stream_options.include_usage') === true;
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Hello')]);

    agent()->prompt('Hello', provider: 'litellm');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => fakeLiteLLMResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'litellm');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('litellm');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-4o',
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

    $response = agent()->prompt('Hello', provider: 'litellm');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function () {
    Http::fake(['*' => fakeLiteLLMResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'litellm');

    expect($response->structured['symbol'])->toBe('Au');
});
