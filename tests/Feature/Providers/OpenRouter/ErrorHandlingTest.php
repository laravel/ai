<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);

    expect(fn () => agent()->prompt('Hello', provider: 'openrouter'))
        ->toThrow(RequestException::class);
});

test('rate limit response throws rate limited exception', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    expect(fn () => agent()->prompt('Hello', provider: 'openrouter'))
        ->toThrow(RateLimitedException::class);
});

test('error in 200 response throws ai exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The model does not exist.',
        ],
    ])]);

    expect(fn () => agent()->prompt('Hello', provider: 'openrouter'))
        ->toThrow(AiException::class, 'OpenRouter Error');
});
