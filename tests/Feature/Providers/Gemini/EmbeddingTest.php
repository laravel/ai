<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

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

test('request sends x-goog-api-key header', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('x-goog-api-key', 'test-key');
    });
});
