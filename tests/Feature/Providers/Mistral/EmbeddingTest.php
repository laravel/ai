<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request includes model and input', function () {
    Http::fake(['*' => fakeEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'mistral-embed'
            && $body['input'] === ['Hello world']
            && $request->url() === 'https://api.mistral.ai/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'mistral', model: 'mistral-embed');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('mistral');
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'mistral-embed',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['total_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'mistral', model: 'mistral-embed');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limit_error',
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'object' => 'error',
            'message' => 'The server is currently overloaded.',
            'type' => 'server_error',
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'api.mistral.ai/*' => Http::response([
            'object' => 'error',
            'message' => 'Invalid API key',
            'type' => 'authentication_error',
        ], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'mistral', model: 'mistral-embed');
})->throws(RequestException::class);

function fakeEmbeddingsResponse()
{
    return Http::response([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'mistral-embed',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['total_tokens' => 10],
    ]);
}
