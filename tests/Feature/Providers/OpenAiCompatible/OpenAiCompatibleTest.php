<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    configureOpenAiCompatible();
});

test('throws when no url is configured', function () {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    expect(fn () => agent()->prompt('Hello', provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, "requires a 'url'");
});

test('throws when no default model is configured and none is passed', function () {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]]);

    expect(fn () => agent()->prompt('Hello', provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'requires a default text model');
});

test('text requests use the configured base url and path', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'openai-compatible');

    expect($response->text)->toBe('Hello from local model')
        ->and($response->meta->provider)->toBe('openai-compatible');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://localhost:1234/v1/chat/completions');
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('request omits authorization header when no key is configured', function () {
    config(['ai.providers.openai-compatible' => [
        ...config('ai.providers.openai-compatible'),
        'key' => null,
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

test('structured output defaults to json schema response format', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai-compatible');

    Http::assertSent(function (Request $request) {
        $format = data_get(json_decode($request->body(), true), 'response_format');

        return $format['type'] === 'json_schema'
            && $format['json_schema']['strict'] === true;
    });
});

test('max tokens uses the max_tokens field by default', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'max_tokens') === 4096
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('response usage is parsed using the openai standard shape', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-1',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_tokens_details' => ['cached_tokens' => 40],
            'completion_tokens_details' => ['reasoning_tokens' => 10],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai-compatible');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->cacheReadInputTokens)->toBe(40)
        ->and($response->usage->reasoningTokens)->toBe(10);
});

function configureOpenAiCompatible(): void
{
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);
}

function fakeOpenAiCompatibleResponse(string $content)
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}
