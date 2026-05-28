<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.qianfan' => [
        ...config('ai.providers.qianfan'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'qianfan', model: 'ernie-4.5-turbo-128k');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'ernie-4.5-turbo-128k'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($message) => $message['role'] === 'user' && $message['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMessage = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMessage !== null
            && str_contains($systemMessage['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_completion_tokens') === 4096;
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('structured output uses json object response format', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'response_format') === ['type' => 'json_object'];
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    agent()->prompt('Hello', provider: 'qianfan');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'qianfan');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('qianfan');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => $this->fakeQianfanResponse('Hello')]);

    $response = agent()->prompt('Hello', provider: 'qianfan');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});
