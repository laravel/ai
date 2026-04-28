<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;

beforeEach(function () {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

function fakeGeminiEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'embeddings' => [
            ['values' => [0.1, 0.2, 0.3]],
        ],
        'usageMetadata' => [
            'promptTokenCount' => 10,
        ],
    ]);
}

test('embeddings request posts to batchEmbedContents endpoint', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'models/gemini-embedding-001:batchEmbedContents');
    });
});

test('embeddings request wraps each input in a request object', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $firstRequest = data_get($body, 'requests.0');

        return $firstRequest['model'] === 'models/gemini-embedding-001'
            && $firstRequest['content']['parts'][0]['text'] === 'Hello world'
            && data_get($firstRequest, 'output_dimensionality') === 3072;
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-embedding-001');
});

test('multiple inputs are sent as separate requests in the batch', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [
                ['values' => [0.1, 0.2, 0.3]],
                ['values' => [0.4, 0.5, 0.6]],
            ],
            'usageMetadata' => ['promptTokenCount' => 20],
        ]),
    ]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return count($body['requests']) === 2
            && $body['requests'][0]['content']['parts'][0]['text'] === 'Hello'
            && $body['requests'][1]['content']['parts'][0]['text'] === 'World';
    });

    expect($response->embeddings)->toHaveCount(2);
});

test('explicit dimensions are sent as output_dimensionality', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'requests.0.output_dimensionality') === 768;
    });
});

test('multimodal embeddings require preview model', function () {
    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(InvalidArgumentException::class, 'gemini-embedding-2-preview');

test('base64 image embeddings are sent as inline data', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'requests.0.content.parts.0.inlineData.mimeType') === 'image/png'
            && data_get($body, 'requests.0.content.parts.0.inlineData.data') === base64_encode('image-bytes');
    });
});

test('provider file embeddings resolve to Gemini file uris', function () {
    Http::fake(function (Request $request) {
        return match ([$request->method(), $request->url()]) {
            ['GET', 'https://generativelanguage.googleapis.com/v1beta/files/file_123'] => Http::response([
                'name' => 'files/file_123',
                'mimeType' => 'image/png',
                'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/file_123',
            ]),
            ['POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2-preview:batchEmbedContents'] => fakeGeminiEmbeddingsResponse(),
            default => Http::response(['unexpected_url' => $request->url()], 500),
        };
    });

    Embeddings::for([
        Image::fromId('file_123'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'https://generativelanguage.googleapis.com/v1beta/files/file_123'
        && data_get($request->data(), 'requests.0.content.parts.0.fileData.mimeType') === 'image/png');
});

test('multimodal embeddings accept canonical model names', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'models/gemini-embedding-2-preview');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'models/gemini-embedding-2-preview:batchEmbedContents')
        && ! str_contains($request->url(), 'models/models/'));
});

test('remote video embeddings preserve file uris', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Video::fromUrl('https://www.youtube.com/watch?v=demo'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'https://www.youtube.com/watch?v=demo');
});

test('missing embeddings key in response returns empty array', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'usageMetadata' => ['promptTokenCount' => 5],
        ]),
    ]);

    $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->embeddings)->toBe([]);
});

test('missing usageMetadata in response returns zero tokens', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [['values' => [0.1, 0.2, 0.3]]],
        ]),
    ]);

    $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->tokens)->toBe(0);
});

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(RateLimitedException::class);

test('http error response throws request exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(RequestException::class);

test('request sends x-goog-api-key header', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('x-goog-api-key', 'test-key');
    });
});
