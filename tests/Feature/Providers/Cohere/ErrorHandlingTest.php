<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;

beforeEach(function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Service overloaded'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Service overloaded'], 503),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function () {
    Http::fake([
        'api.cohere.com/*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RequestException::class);
