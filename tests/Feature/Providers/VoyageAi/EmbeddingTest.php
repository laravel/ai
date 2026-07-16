<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;

beforeEach(function (): void {
    config(['ai.providers.voyageai' => [
        ...config('ai.providers.voyageai'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request includes model, input, and output_dimension', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'voyage-4'
            && $body['input'] === ['Hello world']
            && $body['output_dimension'] === 1024
            && $request->url() === 'https://api.voyageai.com/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'voyageai', model: 'voyage-4');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('voyageai')
        ->and($response->meta->model)->toBe('voyage-4');
});

test('embeddings request sends bearer token', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('multiple inputs return multiple embeddings', function (): void {
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

test('embeddings default to 1024 dimensions when none specified', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['output_dimension'] === 1024);
});

test('image embeddings use the multimodal endpoint', function (): void {
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

test('image embeddings require a multimodal model', function (): void {
    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'voyageai');
})->throws(InvalidArgumentException::class, 'voyage-multimodal-3.5');

test('video embeddings require the latest multimodal model', function (): void {
    Embeddings::for([
        Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
    ])->generate(provider: 'voyageai', model: 'voyage-multimodal-3');
})->throws(InvalidArgumentException::class, 'voyage-multimodal-3.5');

test('base64 video embeddings use the multimodal endpoint', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    $response = Embeddings::for([
        Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
            && data_get($body, 'model') === 'voyage-multimodal-3.5'
            && data_get($body, 'inputs.0.content.0.type') === 'video_base64'
            && data_get($body, 'inputs.0.content.0.video_base64') === 'data:video/mp4;base64,'.base64_encode('video-bytes');
    });

    expect($response->embeddings)->toHaveCount(1);
});

test('remote video embeddings preserve video urls', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for([
        Video::fromUrl('https://example.com/demo.mp4'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'inputs.0.content.0.type') === 'video_url'
            && data_get($body, 'inputs.0.content.0.video_url') === 'https://example.com/demo.mp4';
    });
});

test('multimodal embeddings reject mixed url and base64 media sources', function (): void {
    Http::preventStrayRequests();

    Embeddings::for([
        Image::fromUrl('https://example.com/avatar.png'),
        Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');
})->throws(InvalidArgumentException::class, 'either URL media or base64 media exclusively');

test('multimodal embeddings allow mixed media types with the same source type', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for([
        Image::fromUrl('https://example.com/avatar.png'),
        Video::fromUrl('https://example.com/demo.mp4'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'inputs.0.content.0.type') === 'image_url'
            && data_get($body, 'inputs.1.content.0.type') === 'video_url';
    });
});

test('multimodal embeddings allow text mixed with media inputs', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    $response = Embeddings::for([
        'Hello world',
        Image::fromUrl('https://example.com/avatar.png'),
        Video::fromUrl('https://example.com/demo.mp4'),
    ])->dimensions(1024)->generate(provider: 'voyageai', model: 'voyage-multimodal-3.5');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.voyageai.com/v1/multimodalembeddings'
            && data_get($body, 'inputs.0.content.0.type') === 'text'
            && data_get($body, 'inputs.0.content.0.text') === 'Hello world'
            && data_get($body, 'inputs.1.content.0.type') === 'image_url'
            && data_get($body, 'inputs.2.content.0.type') === 'video_url';
    });

    expect($response->embeddings)->toHaveCount(1);
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Rate limit exceeded'], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Service unavailable'], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function (): void {
    Http::fake([
        'api.voyageai.com/*' => Http::response(['detail' => 'Invalid model'], 400),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'voyageai', model: 'voyage-4');
})->throws(RequestException::class);

test('embeddings request includes provider options in the request body', function (): void {
    Http::fake(['*' => fakeVoyageEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->withProviderOptions(['input_type' => 'query', 'truncation' => true])
        ->generate(provider: 'voyageai', model: 'voyage-4');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['input_type'] === 'query'
            && $body['truncation'] === true;
    });
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
