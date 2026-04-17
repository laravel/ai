<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
