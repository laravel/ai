<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function () {
    config(['ai.providers.jina' => [
        ...config('ai.providers.jina'),
        'key' => 'test-key',
    ]]);
});

test('reranking request includes model, query, and documents', function () {
    Http::fake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'jina', model: 'jina-reranker-v3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jina-reranker-v3'
            && $body['query'] === 'What is Laravel?'
            && $body['documents'] === ['Laravel is a PHP framework', 'React is a JS library']
            && ! array_key_exists('top_n', $body)
            && $request->url() === 'https://api.jina.ai/v1/rerank';
    });
});

test('reranking request includes top_n when limit set', function () {
    Http::fake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['top_n'] === 2);
});

test('reranking response is correctly parsed into RankedDocuments', function () {
    Http::fake(['*' => fakeJinaRerankingResponse()]);

    $response = Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'jina', model: 'jina-reranker-v3');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('Laravel is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('jina')
        ->and($response->meta->model)->toBe('jina-reranker-v3');
});

test('reranking request sends bearer token', function () {
    Http::fake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('reranking uses default model when none specified', function () {
    Http::fake(['*' => fakeJinaRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'jina-reranker-v3');
});

test('reranking maps documents by index when results are returned out of order', function () {
    Http::fake(['*' => Http::response([
        'results' => [
            ['index' => 2, 'relevance_score' => 0.91],
            ['index' => 0, 'relevance_score' => 0.42],
            ['index' => 1, 'relevance_score' => 0.10],
        ],
        'model' => 'jina-reranker-v3',
        'usage' => ['tokens' => 25],
    ])]);

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'jina', model: 'jina-reranker-v3');

    $ranked = $response->collect();

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throws when the API returns an error', function () {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function () {
    Http::fake(['api.jina.ai/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function () {
    Http::fake(['api.jina.ai/*' => Http::response(['detail' => 'service unavailable'], 503)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'jina', model: 'jina-reranker-v3');
})->throws(ProviderOverloadedException::class);

function fakeJinaRerankingResponse()
{
    return Http::response([
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
        'model' => 'jina-reranker-v3',
        'usage' => ['tokens' => 25],
    ]);
}
