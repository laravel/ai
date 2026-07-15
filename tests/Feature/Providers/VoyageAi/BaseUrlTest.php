<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

function fakeVoyageBaseUrlEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

function fakeVoyageBaseUrlRerankingResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['index' => 0, 'relevance_score' => 0.9]],
        'model' => 'rerank-2.5-lite',
        'usage' => ['total_tokens' => 5],
    ]);
}

test('voyageai embedding requests use the configured base url', function (): void {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/embeddings');
});

test('voyageai reranking requests use the configured base url', function (): void {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v1',
    ]]);

    Http::fake(['*' => fakeVoyageBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'voyageai', model: 'rerank-2.5-lite');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8080/v1/rerank');
});

test('voyageai requests fall back to the default base url', function (): void {
    config(['ai.providers.voyageai' => array_diff_key(
        [...config('ai.providers.voyageai'), 'key' => 'test-key'],
        ['url' => null],
    )]);

    Http::fake(['*' => fakeVoyageBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.voyageai.com/v1/embeddings');
});
