<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiEmbeddingResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ]);
}

test('embeddings request includes model, input, and dimensions', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-3-small'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 768
            && $request->url() === 'https://api.openai.com/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('text-embedding-3-small');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'text-embedding-3-small');
});

test('embeddings default to 1536 dimensions when none specified', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['dimensions'] === 1536);
});

test('embeddings request includes provider options in the request body', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])
        ->providerOptions(['encoding_format' => 'base64', 'user' => 'tester'])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['encoding_format'] === 'base64'
            && $body['user'] === 'tester'
            && $body['model'] === 'text-embedding-3-small';
    });
});

test('provider options cannot override framework controlled keys', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])
        ->providerOptions(['model' => 'hijacked', 'input' => ['hijacked'], 'dimensions' => 1])
        ->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-3-small'
            && $body['input'] === ['Hello']
            && $body['dimensions'] === 1536;
    });
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Unauthorized',
            ],
        ], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
})->throws(RequestException::class);
