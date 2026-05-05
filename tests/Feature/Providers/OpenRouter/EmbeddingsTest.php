<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request is correctly formatted', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 5],
    ])]);

    Ai::instance('openrouter')->embeddings(['Hello world']);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'google/gemini-embedding-001'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 1536
            && str_contains($request->url(), 'embeddings');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ])]);

    $response = Ai::instance('openrouter')->embeddings(['Hello', 'World']);

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6])
        ->and($response->tokens)->toBe(10);
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openrouter')->embeddings(['test']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request uses openrouter base url', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openrouter')->embeddings(['test']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/embeddings');
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limited']], 429),
    ]);

    Ai::instance('openrouter')->embeddings(['Hello']);
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Server overloaded']], 503),
    ]);

    Ai::instance('openrouter')->embeddings(['Hello']);
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response(['error' => ['message' => 'Bad request']], 400),
    ]);

    Ai::instance('openrouter')->embeddings(['Hello']);
})->throws(RequestException::class);
