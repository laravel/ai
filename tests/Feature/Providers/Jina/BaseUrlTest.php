<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

function fakeJinaBaseUrlEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'model' => 'jina-embeddings-v4',
        'data' => [['index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'usage' => ['total_tokens' => 5],
    ]);
}

function fakeJinaBaseUrlRerankingResponse(): PromiseInterface
{
    return Http::response([
        'model' => 'jina-reranker-v3',
        'results' => [['index' => 0, 'relevance_score' => 0.9]],
    ]);
}

test('jina embedding requests use the configured base url', function () {
    config(['ai.providers.jina' => [
        ...config('ai.providers.jina'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeJinaBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8080/v1/embeddings');
});

test('jina reranking requests use the configured base url', function () {
    config(['ai.providers.jina' => [
        ...config('ai.providers.jina'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeJinaBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'jina', model: 'jina-reranker-v3');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8080/v1/rerank');
});

test('jina requests fall back to the default base url', function () {
    config(['ai.providers.jina' => array_diff_key(
        [...config('ai.providers.jina'), 'key' => 'test-key'],
        ['url' => null],
    )]);

    Http::fake(['*' => fakeJinaBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.jina.ai/v1/embeddings');
});
