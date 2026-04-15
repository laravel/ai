<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('request includes model and input', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'azure', model: 'gpt-4o');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'gpt-4o'
            && is_array($body['input'])
            && collect($body['input'])->contains(fn ($m) => $m['role'] === 'user'
                && collect($m['content'])->contains(fn ($c) => ($c['text'] ?? '') === 'Hi there'));
    });
});

test('system instructions are sent as system message in input', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('temperature and max tokens are included when set via attributes', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_output_tokens') === 4096;
    });
});

test('temperature and max tokens are excluded when not set', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_output_tokens', $body);
    });
});

test('tools include tool choice auto', function () {
    Http::fake(['*' => fakeAzureResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'auto'
            && is_array($body['tools'])
            && count($body['tools']) > 0;
    });
});

test('request without tools excludes tool fields', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('structured output includes json schema text format', function () {
    Http::fake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $format = data_get($body, 'text.format');

        return ($format['type'] ?? '') === 'json_schema'
            && isset($format['name'])
            && isset($format['schema'])
            && $format['strict'] === true;
    });
});

test('request without schema excludes text format', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('text', $body);
    });
});

test('streaming request includes stream flag', function () {
    Http::fake(['*' => Http::response(
        body: "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"in_progress\",\"output\":[]}}\n\ndata: {\"type\":\"response.output_text.delta\",\"delta\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.output_text.done\",\"text\":\"Hi\",\"item_id\":\"msg_1\",\"output_index\":0,\"content_index\":0}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"model\":\"gpt-4o\",\"status\":\"completed\",\"output\":[{\"type\":\"message\",\"status\":\"completed\",\"role\":\"assistant\",\"content\":[{\"type\":\"output_text\",\"text\":\"Hi\"}]}],\"usage\":{\"input_tokens\":1,\"output_tokens\":1,\"input_tokens_details\":{\"cached_tokens\":0},\"output_tokens_details\":{\"reasoning_tokens\":0}}}}\n\n",
    )]);

    $stream = agent()->stream('Hello', provider: 'azure');

    foreach ($stream as $event) {
        //
    }

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true;
    });
});

test('request sends api-key header authentication', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('api-key', 'test-key');
    });
});

test('request does not include api-version query parameter', function () {
    Http::fake(['*' => fakeAzureResponse('Hello')]);

    agent()->prompt('Hello', provider: 'azure');

    Http::assertSent(function (Request $request) {
        return ! str_contains($request->url(), 'api-version');
    });
});

test('response text is correctly parsed', function () {
    Http::fake(['*' => fakeAzureResponse('Laravel is great')]);

    $response = agent()->prompt('Tell me about Laravel', provider: 'azure');

    expect($response->text)->toBe('Laravel is great')
        ->and($response->meta->provider)->toBe('azure');
});

test('response usage is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'id' => 'resp_azure_123',
        'status' => 'completed',
        'model' => 'gpt-4o',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => 'Hello']],
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'azure');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});

test('structured response is correctly parsed', function () {
    Http::fake(['*' => fakeAzureResponse('{"symbol": "Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'azure');

    expect($response->structured['symbol'])->toBe('Au');
});
