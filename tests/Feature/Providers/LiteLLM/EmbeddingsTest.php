<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;

beforeEach(function () {
    config(['ai.providers.litellm' => [
        ...config('ai.providers.litellm'),
        'key' => 'test-key',
    ]]);
});

test('embeddings request is correctly formatted', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
        ],
        'usage' => ['prompt_tokens' => 5],
    ])]);

    Ai::instance('litellm')->embeddings(['Hello world']);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'openai/text-embedding-3-small'
            && $body['input'] === ['Hello world']
            && $body['dimensions'] === 768
            && str_contains($request->url(), 'embeddings');
    });
});

test('embeddings response is correctly parsed', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['prompt_tokens' => 10],
    ])]);

    $response = Ai::instance('litellm')->embeddings(['Hello', 'World']);

    expect($response->embeddings)->toHaveCount(2)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->embeddings[1])->toBe([0.4, 0.5, 0.6])
        ->and($response->tokens)->toBe(10);
});

test('embeddings request sends bearer token', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('litellm')->embeddings(['test']);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('embeddings request uses litellm base url', function () {
    Http::fake(['*' => Http::response([
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1]]],
        'usage' => ['prompt_tokens' => 1],
    ])]);

    Ai::instance('litellm')->embeddings(['test']);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:4000/embeddings');
});
