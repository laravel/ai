<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and input', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'openai', model: 'gpt-5.4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-5.4'
            && is_array($body['input'])
            && collect($body['input'])->contains(fn ($m) => $m['role'] === 'user'
                && collect($m['content'])->contains(fn ($c) => ($c['text'] ?? '') === 'Hi there'));
    });
});

test('system instructions are sent as system message in input', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_output_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeOpenAiResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function () {
    Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'text.format');

        return $format['type'] === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('request without schema excludes text format', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeOpenAiResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => fakeOpenAiResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'openai');

    expect($response->text)->toBe('Laravel is great');
    expect($response->meta->provider)->toBe('openai');
});

test('response usage is correctly parsed', function () {
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

    expect($response->usage->promptTokens)->toBe(10);
    expect($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function () {
    Http::fake(['*' => fakeOpenAiResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai');

    expect($response->structured['symbol'])->toBe('Au');
});
