<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

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

test('overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 503,
                'message' => 'The model is overloaded. Please try again later.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(ProviderOverloadedException::class);

test('http error response throws request exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(ProviderRequestException::class);

test('request sends x-goog-api-key header', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('x-goog-api-key', 'test-key');
    });
});

test('embeddings request merges provider options into each per-input request', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello', 'World'])
        ->providerOptions(['taskType' => 'RETRIEVAL_QUERY', 'title' => 'doc'])
        ->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        if (array_key_exists('taskType', $body) || array_key_exists('title', $body)) {
            return false;
        }

        foreach ($body['requests'] as $req) {
            if (($req['taskType'] ?? null) !== 'RETRIEVAL_QUERY' || ($req['title'] ?? null) !== 'doc') {
                return false;
            }

            if (! isset($req['model'], $req['content'], $req['output_dimensionality'])) {
                return false;
            }
        }

        return count($body['requests']) === 2;
    });
});

test('gemini provider options cannot override framework controlled per-request keys', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])
        ->providerOptions([
            'model' => 'hijacked',
            'content' => ['hijacked'],
            'output_dimensionality' => 1,
        ])
        ->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $req = $body['requests'][0];

        return $req['model'] === 'models/gemini-embedding-001'
            && $req['content'] === ['parts' => [['text' => 'Hello']]]
            && $req['output_dimensionality'] === 3072;
    });
});
