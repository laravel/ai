<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'message' => 'Invalid API key',
            'type' => 'authentication_error',
        ], 401),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limit_error',
        ], 429),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(RateLimitedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'error' => [
                'type' => 'server_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(AiException::class, 'Mistral Error');
