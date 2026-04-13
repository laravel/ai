<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('request includes model and messages', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'ollama', model: 'llama3.1:8b');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'llama3.1:8b'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are sent in options object', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'options.temperature') === 0.7
            && data_get($body, 'options.num_predict') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('options', $body)
            || (! array_key_exists('temperature', $body['options'] ?? [])
                && ! array_key_exists('num_predict', $body['options'] ?? []));
    });
});

test('tools are included in the request', function () {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tools field', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body);
    });
});

test('structured output includes format field', function () {
    Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return array_key_exists('format', $body)
            && is_array($body['format']);
    });
});

test('request without schema excludes format field', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('format', $body);
    });
});

test('non-streaming request sets stream to false', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['stream'] === false;
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => $this->fakeTextResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'ollama');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('ollama');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'model' => 'llama3.1:8b',
        'message' => ['role' => 'assistant', 'content' => 'Hello'],
        'done_reason' => 'stop',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ])]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function () {
    Http::fake(['*' => $this->fakeStructuredResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'ollama');

    expect($response->structured['symbol'])->toBe('Au');
});

test('request is sent to the chat endpoint', function () {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/api/chat');
    });
});
