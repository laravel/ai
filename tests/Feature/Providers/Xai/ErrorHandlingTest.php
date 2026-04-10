<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invalid API key',
            ],
        ], 401),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');
})->throws(RateLimitedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');
})->throws(AiException::class, 'xAI Error');

test('failed status response throws ai exception', function () {
    Http::fake([
        '*' => Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'failed',
            'error' => [
                'code' => 'server_error',
                'message' => 'The response failed.',
            ],
            'output' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ], 200),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');
})->throws(AiException::class, 'The response failed.');
