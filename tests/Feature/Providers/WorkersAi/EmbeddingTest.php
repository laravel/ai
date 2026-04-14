<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('embeddings request includes model and input', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === '@cf/baai/bge-large-en-v1.5'
            && $body['input'] === ['Hello world']
            && str_ends_with($request->url(), '/embeddings');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'workersai');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('workersai');
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['total_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'workersai');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings request uses correct base url', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account/ai/v1/embeddings';
    });
});

test('embeddings request sends bearer token', function () {
    Http::fake(['api.cloudflare.com/*' => fakeWorkersAiEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('embeddings validates model name on compat endpoint', function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'url' => 'https://gateway.ai.cloudflare.com/v1/abc/gw/compat',
    ]]);

    Embeddings::for(['Hello'])->generate(provider: 'workersai');
})->throws(\Laravel\Ai\Exceptions\AiException::class, "requires the 'workers-ai/' prefix");

function fakeWorkersAiEmbeddingsResponse()
{
    return Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'model' => '@cf/baai/bge-large-en-v1.5',
        'usage' => ['total_tokens' => 10],
    ]);
}
