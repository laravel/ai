<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

function fakeCohereBaseUrlEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
        'meta' => ['billed_units' => ['input_tokens' => 5]],
    ]);
}

function fakeCohereBaseUrlRerankingResponse(): PromiseInterface
{
    return Http::response([
        'results' => [['index' => 0, 'relevance_score' => 0.9]],
        'meta' => ['billed_units' => ['search_units' => 1]],
    ]);
}

test('cohere embedding requests use the configured base url', function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v2',
    ]]);

    Http::fake(['*' => fakeCohereBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8080/v2/embed');
});

test('cohere reranking requests use the configured base url', function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
        'url' => 'http://localhost:8080/v2',
    ]]);

    Http::fake(['*' => fakeCohereBaseUrlRerankingResponse()]);

    Reranking::of(['doc1'])->rerank('What is AI?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(fn (Request $r) => $r->url() === 'http://localhost:8080/v2/rerank');
});

test('cohere requests fall back to the default base url', function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => fakeCohereBaseUrlEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.cohere.com/v2/embed');
});
