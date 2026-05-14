<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

beforeEach(function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('reranking request includes model, query, and documents', function () {
    Http::fake(['*' => fakeCohereRerankingResponse()]);

    Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'rerank-v3.5'
            && $body['query'] === 'What is Laravel?'
            && $body['documents'] === ['Laravel is a PHP framework', 'React is a JS library']
            && ! array_key_exists('top_n', $body)
            && $request->url() === 'https://api.cohere.com/v2/rerank';
    });
});

test('reranking request includes top_n when limit set', function () {
    Http::fake(['*' => fakeCohereRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->limit(2)
        ->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['top_n'] === 2);
});

test('reranking response is correctly parsed into RankedDocuments', function () {
    Http::fake(['*' => fakeCohereRerankingResponse()]);

    $response = Reranking::of(['Laravel is a PHP framework', 'React is a JS library'])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    expect($response)->toHaveCount(2)
        ->and($response->first())->toBeInstanceOf(RankedDocument::class)
        ->and($response->first()->index)->toBe(0)
        ->and($response->first()->document)->toBe('Laravel is a PHP framework')
        ->and($response->first()->score)->toBe(0.95)
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('rerank-v3.5');
});

test('reranking request sends bearer token', function () {
    Http::fake(['*' => fakeCohereRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('reranking uses default model when none specified', function () {
    Http::fake(['*' => fakeCohereRerankingResponse()]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'rerank-v3.5');
});

test('reranking maps documents by index when results are returned out of order', function () {
    Http::fake(['*' => Http::response([
        'results' => [
            ['index' => 2, 'relevance_score' => 0.91],
            ['index' => 0, 'relevance_score' => 0.42],
            ['index' => 1, 'relevance_score' => 0.10],
        ],
    ])]);

    $response = Reranking::of(['Doc A', 'Doc B', 'Doc C'])
        ->rerank('query', provider: 'cohere', model: 'rerank-v3.5');

    $ranked = $response->collect();

    expect($ranked[0]->index)->toBe(2)
        ->and($ranked[0]->document)->toBe('Doc C')
        ->and($ranked[0]->score)->toBe(0.91)
        ->and($ranked[1]->index)->toBe(0)
        ->and($ranked[1]->document)->toBe('Doc A');
});

test('reranking throws when the API returns an error', function () {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RequestException::class);

test('reranking rate limit response throws rate limited exception', function () {
    Http::fake(['api.cohere.com/*' => Http::response(['message' => 'rate limit exceeded'], 429)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
})->throws(RateLimitedException::class);

test('reranking overloaded response throws provider overloaded exception', function () {
    Http::fake(['api.cohere.com/*' => Http::response(['message' => 'service unavailable'], 503)]);

    Reranking::of(['Doc A', 'Doc B'])->rerank('query', provider: 'cohere', model: 'rerank-v3.5');
})->throws(ProviderOverloadedException::class);

function fakeCohereRerankingResponse()
{
    return Http::response([
        'results' => [
            ['index' => 0, 'relevance_score' => 0.95],
            ['index' => 1, 'relevance_score' => 0.12],
        ],
    ]);
}
