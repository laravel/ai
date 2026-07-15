<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function (): void {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'message' => 'Invalid API key',
            'type' => 'authentication_error',
        ], 401),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limit_error',
        ], 429),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        '*' => Http::response([
            'object' => 'error',
            'message' => 'The server is currently overloaded. Please try again later.',
            'type' => 'server_error',
        ], 503),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function (): void {
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
