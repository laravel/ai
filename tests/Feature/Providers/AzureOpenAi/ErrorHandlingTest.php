<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.cognitiveservices.azure.com',
        'deployment' => 'gpt-4o',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'max_tokens: must be at least 1',
            ],
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'azure',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'azure',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'azure',
    );
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        'my-resource.cognitiveservices.azure.com/*' => Http::response([
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'azure',
    );
})->throws(AiException::class, 'OpenAI Error');
