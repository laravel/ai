<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

function fakeCustomHeadersEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]]],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 5],
    ]);
}

test('configured headers are sent with provider requests', function (): void {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
        'headers' => ['X-Session-Affinity' => 'abc-123'],
    ]]);

    Http::fake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $r): bool => $r->header('X-Session-Affinity') === ['abc-123']);
});

test('configured headers override gateway default headers', function (): void {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
        'headers' => ['Authorization' => 'Bearer proxy-token'],
    ]]);

    Http::fake(['*' => fakeCustomHeadersEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $r): bool => $r->header('Authorization') === ['Bearer proxy-token']);
});
