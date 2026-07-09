<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

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
