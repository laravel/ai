<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\AttributeAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('request includes model and input', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'xai', model: 'grok-4-1-fast-reasoning');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'grok-4-1-fast-reasoning'
            && is_array($body['input'])
            && collect($body['input'])->contains(fn ($m) => $m['role'] === 'user'
                && collect($m['content'])->contains(fn ($c) => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'Hi there'));
    });
});

test('system instructions are sent as system message', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_output_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

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
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('streaming request includes stream flag', function () {
    $sseData = "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_123\",\"model\":\"grok-4-1-fast-reasoning\"}}\n\n"
        ."data: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\"}\n\n"
        ."data: {\"type\":\"response.output_text.done\"}\n\n"
        ."data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_123\",\"usage\":{\"input_tokens\":1,\"output_tokens\":1}}}\n\n";

    Http::fake(['*' => Http::response($sseData)]);

    $stream = agent()->stream('Hello', provider: 'xai');

    foreach ($stream as $event) {
        //
    }

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Hello')]);

    agent()->prompt('Hello', provider: 'xai');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'xai');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('xai');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => 'Hello'],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'xai');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function () {
    Http::fake(['*' => fakeXaiRequestMappingResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'xai');

    expect($response->structured['symbol'])->toBe('Au');
});

function fakeXaiRequestMappingResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}
