<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request includes model, texts, input_type, and embedding_types', function () {
    Http::fake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'embed-v4.0'
            && $body['texts'] === ['Hello world']
            && $body['input_type'] === 'search_document'
            && $body['embedding_types'] === ['float']
            && $request->url() === 'https://api.cohere.com/v2/embed';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeCohereEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('embed-v4.0');
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings use default model when none specified', function () {
    Http::fake(['*' => fakeCohereEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['model'] === 'embed-v4.0');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
        'meta' => ['billed_units' => ['input_tokens' => 20]],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'cohere', model: 'embed-v4.0');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings throw when the API returns an error', function () {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    Embeddings::for(['Hello'])->generate(provider: 'cohere', model: 'embed-v4.0');
})->throws(RequestException::class);

function fakeCohereEmbeddingsResponse()
{
    return Http::response([
        'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
        'meta' => ['billed_units' => ['input_tokens' => 10]],
    ]);
}
