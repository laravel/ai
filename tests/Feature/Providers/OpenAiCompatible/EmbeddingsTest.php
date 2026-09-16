<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => [
            'text' => ['default' => 'local-model'],
            'embeddings' => ['default' => 'local-embeddings-model', 'dimensions' => 768],
        ],
    ]]);
});

test('embeddings request is correctly formatted', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 5],
    ])]);

    Ai::instance('openai-compatible')->embeddings(['Hello world']);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'local-embeddings-model'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 768
            && $request->url() === 'http://localhost:1234/v1/embeddings';
    });
});

test('embeddings response is correctly parsed', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ])]);

    $response = Ai::instance('openai-compatible')->embeddings(['Hello', 'World']);

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('openai-compatible');
});

test('embeddings request sends bearer token', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openai-compatible')->embeddings(['test']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request omits authorization header when no key is configured', function (): void {
    config(['ai.providers.openai-compatible.key' => null]);

    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openai-compatible')->embeddings(['test']);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('embeddings request includes provider options in the request body', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openai-compatible')->embeddings(
        inputs: ['Hello'],
        providerOptions: ['user' => 'test-user'],
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['user'] === 'test-user'
            && $body['input'] === ['Hello'];
    });
});

test('embeddings reject response formats that do not return float vectors', function (): void {
    expect(fn () => Ai::instance('openai-compatible')->embeddings(
        inputs: ['Hello'],
        providerOptions: ['encoding_format' => 'base64'],
    ))->toThrow(InvalidArgumentException::class, 'only supports float embedding responses');
});

test('throws when no default embeddings model is configured and none is passed', function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(InvalidArgumentException::class, 'requires a default embeddings model');
});

test('embeddings request omits dimensions when none are configured', function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
    ]]);

    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openai-compatible')->embeddings(['Hello']);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'local-embeddings-model'
            && ! array_key_exists('dimensions', $body);
    });
});

test('embeddings request omits dimensions when an explicit model is passed', function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]]);

    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('openai-compatible')->embeddings(['Hello'], model: 'explicit-embeddings-model');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'explicit-embeddings-model'
            && ! array_key_exists('dimensions', $body);
    });
});

test('automatic embedding fakes require dimensions when the model dimensions are unknown', function (): void {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'models' => ['embeddings' => ['default' => 'local-embeddings-model']],
    ]]);

    Embeddings::fake();

    Embeddings::for(['Hello'])->generate(provider: 'openai-compatible');
})->throws(RuntimeException::class, 'Unable to generate fake embeddings without positive dimensions');

test('embeddings reject responses with missing vectors', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'usage' => ['prompt_tokens' => 1],
    ])]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(AiException::class, 'expected number of embeddings');
});

test('embeddings reject malformed vectors', function (): void {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => ['invalid']]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(AiException::class, 'invalid embedding vector');
});

test('embeddings rate limit response throws rate limited exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(RateLimitedException::class);
});

test('embeddings overloaded response throws provider overloaded exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Server overloaded']], 503)]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(ProviderOverloadedException::class);
});

test('embeddings error in 200 response throws ai exception', function (): void {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The model does not exist.',
        ],
    ])]);

    expect(fn () => Ai::instance('openai-compatible')->embeddings(['Hello']))
        ->toThrow(AiException::class, 'OpenAI-compatible Error');
});
