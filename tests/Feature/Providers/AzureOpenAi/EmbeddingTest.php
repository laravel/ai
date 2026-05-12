<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
        'embedding_deployment' => 'text-embedding-3-small',
    ]]);
});

test('embeddings request includes model input and dimensions', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    Embeddings::for(['Hello world'])->dimensions(1536)->generate(provider: 'azure', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'text-embedding-3-small'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 1536;
    });
});

test('embeddings request uses the v1 embeddings endpoint', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/openai/v1/embeddings');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'azure', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toHaveCount(3)
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('azure');
});

test('embeddings request sends api-key header', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('api-key', 'test-key');
    });
});

test('embeddings request does not include api-version query parameter', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        return ! str_contains($request->url(), 'api-version');
    });
});

test('multiple inputs return multiple embeddings', function () {
    Http::fake(['*' => Http::response([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'text-embedding-3-small',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 20],
    ])]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'azure', model: 'text-embedding-3-small');

    expect($response->embeddings)->toHaveCount(2);
});

test('embeddings rate limit response throws rate limited exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
})->throws(RateLimitedException::class);

test('embeddings overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
})->throws(ProviderOverloadedException::class);

test('embeddings http error response throws request exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid input',
            ],
        ], 400),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'azure', model: 'text-embedding-3-small');
})->throws(RequestException::class);

test('embeddings request includes provider options in the request body', function () {
    Http::fake(['*' => fakeAzureEmbeddingsResponse()]);

    Embeddings::for(['Hello'])
        ->providerOptions(['encoding_format' => 'base64', 'user' => 'tester'])
        ->generate(provider: 'azure', model: 'text-embedding-3-small');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['encoding_format'] === 'base64'
            && $body['user'] === 'tester'
            && $body['model'] === 'text-embedding-3-small';
    });
});

function fakeAzureEmbeddingsResponse()
{
    return Http::response([
        'id' => 'embd-123',
        'object' => 'list',
        'model' => 'text-embedding-3-small',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ]);
}
