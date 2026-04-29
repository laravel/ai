<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiEmbeddingResponse(): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ]);
}

test('embeddings request includes model, input, and dimensions', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-3-small'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 768
            && $request->url() === 'https://api.openai.com/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('text-embedding-3-small');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'text-embedding-3-small');
});

test('embeddings default to 1536 dimensions when none specified', function () {
    Http::fake(['*' => fakeOpenAiEmbeddingResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['dimensions'] === 1536);
});

test('embeddings throw when the API returns an error', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'unauthorized']], 401)]);

    Embeddings::for(['Hello'])->generate(provider: 'openai', model: 'text-embedding-3-small');
})->throws(RequestException::class);
