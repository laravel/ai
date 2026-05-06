<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;

beforeEach(function () {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
    ]]);
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Service overloaded'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Service overloaded'], 503),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function () {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');
})->throws(RequestException::class);
