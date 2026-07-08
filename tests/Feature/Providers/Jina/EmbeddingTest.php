<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

    Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jina-embeddings-v4'
            && $body['input'][0]['text'] === 'Hello world'
            && $body['dimensions'] === 1024
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

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'jina');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'jina-embeddings-v4');
});

test('embeddings throw when the API returns an error', function () {
    Http::fake(['*' => Http::response(['detail' => 'unauthorized'], 401)]);

    Embeddings::for(['Hello'])->generate(provider: 'jina', model: 'jina-embeddings-v4');
})->throws(RequestException::class);

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

test('embeddings request includes provider options and overrides default task', function () {
    Http::fake(['*' => fakeJinaEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['task' => 'retrieval.query', 'late_chunking' => true])
        ->generate(provider: 'jina', model: 'jina-embeddings-v4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['task'] === 'retrieval.query'
            && $body['late_chunking'] === true
            && $body['model'] === 'jina-embeddings-v4';
    });
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
