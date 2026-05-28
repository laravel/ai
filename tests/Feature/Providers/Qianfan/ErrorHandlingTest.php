<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.qianfan' => [
        ...config('ai.providers.qianfan'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'max_tokens: must be at least 1',
        ],
    ], 400)]);

    (new AssistantAgent)->prompt('Hi', provider: 'qianfan');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'rate_limit_error',
            'message' => 'Rate limit exceeded',
        ],
    ], 429)]);

    (new AssistantAgent)->prompt('Hi', provider: 'qianfan');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'server_error',
            'message' => 'The server is currently overloaded. Please try again later.',
        ],
    ], 503)]);

    (new AssistantAgent)->prompt('Hi', provider: 'qianfan');
})->throws(ProviderOverloadedException::class);

test('402 response throws insufficient credits exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'message' => 'Insufficient Balance',
            'type' => 'insufficient_balance_error',
            'param' => null,
            'code' => 'insufficient_balance',
        ],
    ], 402)]);

    (new AssistantAgent)->prompt('Hi', provider: 'qianfan');
})->throws(InsufficientCreditsException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake(['*' => Http::response([
        'error' => [
            'type' => 'api_error',
            'message' => 'Internal server error',
        ],
    ], 200)]);

    (new AssistantAgent)->prompt('Hi', provider: 'qianfan');
})->throws(AiException::class, 'Qianfan Error');
