<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.nvidia' => [
        ...config('ai.providers.nvidia'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'nvidia', model: 'meta/llama-3.3-70b-instruct');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'meta/llama-3.3-70b-instruct'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_completion_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    agent()->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeNvidiaResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    agent()->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output uses json object response format', function () {
    Http::fake(['*' => fakeNvidiaResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'response_format') === ['type' => 'json_object'];
    });
});

test('request without schema excludes response format', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    agent()->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeNvidiaResponse('Hello')]);

    agent()->prompt('Hello', provider: 'nvidia');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text and meta are correctly parsed', function () {
    Http::fake(['*' => fakeNvidiaResponse('NIM is fast')]);

    $response = agent()->prompt('Tell me about NIM', provider: 'nvidia');

    expect($response->text)->toBe('NIM is fast')
        ->and($response->meta->provider)->toBe('nvidia');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-nv-1',
        'object' => 'chat.completion',
        'model' => 'meta/llama-3.3-70b-instruct',
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

    $response = agent()->prompt('Hello', provider: 'nvidia');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});
