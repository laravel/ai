<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'max_tokens: must be at least 1',
            ],
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(ProviderOverloadedException::class);

test('flex tier capacity exceeded response throws provider overloaded exception', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'error' => [
                'type' => 'capacity_exceeded',
                'message' => 'Flex tier capacity exceeded. Please try again later.',
            ],
        ], 498),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(ProviderOverloadedException::class);

test('undocumented gateway status code does not fail over', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response('gateway timeout', 524),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(RequestException::class);

test('error in 200 response throws ai exception', function (): void {
    Http::fake([
        'api.groq.com/*' => Http::response([
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'groq',
    );
})->throws(AiException::class, 'Groq Error');
