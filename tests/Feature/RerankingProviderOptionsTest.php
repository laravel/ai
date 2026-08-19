<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Reranking;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [...config('ai.providers.cohere'), 'key' => 'test-key']]);
});

function fakeRerankingResponse(): array
{
    return ['results' => [['index' => 0, 'relevance_score' => 0.9]]];
}

test('flat provider options are sent on the reranking request', function (): void {
    Http::fake(['*' => Http::response(fakeRerankingResponse())]);

    Reranking::of(['Laravel is a PHP framework'])
        ->withProviderOptions(['max_tokens_per_doc' => 512])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
});

test('provider options may not override the core reranking request payload', function (): void {
    Http::fake(['*' => Http::response(fakeRerankingResponse())]);

    Reranking::of(['Laravel is a PHP framework'])
        ->withProviderOptions(['model' => 'hijacked', 'query' => 'hijacked'])
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'rerank-v3.5' && $body['query'] === 'What is Laravel?';
    });
});

test('closure provider options receive the resolved reranking provider', function (): void {
    Http::fake(['*' => Http::response(fakeRerankingResponse())]);

    $seen = [];

    Reranking::of(['Laravel is a PHP framework'])
        ->withProviderOptions(function (Provider $provider) use (&$seen): array {
            $seen[] = $provider->driver();

            return ['max_tokens_per_doc' => 512];
        })
        ->rerank('What is Laravel?', provider: 'cohere', model: 'rerank-v3.5');

    expect($seen)->toBe(['cohere']);

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['max_tokens_per_doc'] === 512);
});
