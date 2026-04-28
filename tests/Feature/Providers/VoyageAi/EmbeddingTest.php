<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Image;

beforeEach(function () {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request includes model, input, and output_dimension', function () {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'voyage-4'
            && $body['input'] === ['Hello world']
            && $body['output_dimension'] === 1024
            && $request->url() === 'https://api.voyageai.com/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'voyageai', model: 'voyage-4');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('voyageai')
        ->and($response->meta->model)->toBe('voyage-4');
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'voyageai', model: 'voyage-4');

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6]);
});

test('embeddings default to 1024 dimensions when none specified', function () {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['output_dimension'] === 1024);
});

test('image embeddings use the multimodal endpoint', function () {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    $response = Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
            && data_get($body, 'inputs.0.content.0.type') === 'image_base64'
            && data_get($body, 'inputs.0.content.0.image_base64') === 'data:image/png;base64,'.base64_encode('image-bytes')
            && data_get($body, 'output_dimension') === 1024;
    });

    expect($response->embeddings)->toHaveCount(1);
});

function fakeVoyageEmbeddingsResponse()
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'model' => 'voyage-4',
        'usage' => ['total_tokens' => 10],
    ]);
}
