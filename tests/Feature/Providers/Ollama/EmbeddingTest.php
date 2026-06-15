<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('embeddings request includes model and input', function () {
    Http::fake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'nomic-embed-text'
            && $body['input'] === ['Hello world']
            && str_ends_with($request->url(), '/api/embed');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeOllamaEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('ollama');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'model' => 'nomic-embed-text',
        'embeddings' => [
            [0.1, 0.2, 0.3],
            [0.4, 0.5, 0.6],
        ],
        'prompt_eval_count' => 20,
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings request sends bearer token when key is set', function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('embeddings request sends no authorization header when key is empty', function () {
    Http::fake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    Http::assertSent(function (Request $request) {
        return ! $request->hasHeader('Authorization');
    });
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'rate limit exceeded',
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'server overloaded',
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'model not found',
        ], 400),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'ollama', model: 'nomic-embed-text');
})->throws(ProviderRequestException::class);

test('embeddings request includes provider options in the request body', function () {
    Http::fake(['*' => fakeOllamaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->providerOptions(['truncate' => false, 'keep_alive' => '5m'])
        ->generate(provider: 'ollama', model: 'nomic-embed-text');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['truncate'] === false
            && $body['keep_alive'] === '5m'
            && $body['model'] === 'nomic-embed-text';
    });
});

function fakeOllamaEmbeddingsResponse()
{
    return Http::response([
        'model' => 'nomic-embed-text',
        'embeddings' => [
            [0.1, 0.2, 0.3],
        ],
        'prompt_eval_count' => 10,
    ]);
}
