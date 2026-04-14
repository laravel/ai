<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.deepseek' => [
        ...config('ai.providers.deepseek'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and messages', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'deepseek', model: 'deepseek-chat');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'deepseek-chat'
            && count($body['messages']) >= 1
            && collect($body['messages'])->contains(fn ($m) => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_completion_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeDeepSeekResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema response format', function () {
    Http::fake(['*' => fakeDeepSeekResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'deepseek');

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
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('response_format', $body);
    });
});

test('streaming request includes stream options', function () {
    Http::fake(['*' => Http::response("data: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{\"role\":\"assistant\",\"content\":\"Hi\"},\"finish_reason\":null}]}\n\ndata: {\"id\":\"chatcmpl-123\",\"object\":\"chat.completion.chunk\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":1,\"completion_tokens\":1}}\n\ndata: [DONE]\n\n")]);

    $stream = agent()->stream('Hello', provider: 'deepseek');

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
    Http::fake(['*' => fakeDeepSeekResponse('Hello')]);

    agent()->prompt('Hello', provider: 'deepseek');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => fakeDeepSeekResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'deepseek');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('deepseek');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'deepseek-chat',
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

    $response = agent()->prompt('Hello', provider: 'deepseek');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('reasoning content from deepseek-reasoner is ignored, only content surfaces', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-reasoner-1',
        'object' => 'chat.completion',
        'model' => 'deepseek-reasoner',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'reasoning_content' => 'Let me think... 2+2 = 4',
                'content' => 'The answer is 4.',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 5,
            'completion_tokens' => 3,
        ],
    ])]);

    $response = agent()->prompt('What is 2+2?', provider: 'deepseek', model: 'deepseek-reasoner');

    expect($response->text)->toBe('The answer is 4.');
});
