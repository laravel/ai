<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config(['ai.providers.jina' => [
        ...config('ai.providers.jina'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request includes model, input, and dimensions', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->dimensions(2048)->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jina-embeddings-v4'
            && $body['input'][0]['text'] === 'Hello world'
            && $body['dimensions'] === 2048
            && $body['task'] === 'retrieval.passage'
            && $request->url() === 'https://api.jina.ai/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('jina')
        ->and($response->meta->model)->toBe('jina-embeddings-v4');
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'model' => 'jina-embeddings-v4',
        'object' => 'list',
        'usage' => ['total_tokens' => 20],
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings default to 2048 dimensions when none specified', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['dimensions'] === 2048);
});

function fakeJinaEmbeddingsResponse()
{
    return Http::response([
        'model' => 'jina-embeddings-v4',
        'object' => 'list',
        'usage' => ['total_tokens' => 10],
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
    ]);
}
