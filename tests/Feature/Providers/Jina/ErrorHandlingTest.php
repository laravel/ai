<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;

beforeEach(function (): void {
    config(['ai.providers.jina' => [
        ...config('ai.providers.jina'),
        'key' => 'test-key',
    ]]);
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Service overloaded'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Service overloaded'], 503),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(ProviderOverloadedException::class);

test('reranking http error response throws request exception', function (): void {
    Http::fake([
        'api.jina.ai/*' => Http::response(['detail' => 'Unauthorized'], 401),
    ]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RequestException::class);
