<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'type' => 'server_error',
                'message' => 'The server is currently overloaded. Please try again later.',
            ],
        ], 503),
    ]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
})->throws(ProviderOverloadedException::class);

test('insufficient credit response throws insufficient credits exception', function (string $message) {
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'type' => 'payment_required',
                'message' => $message,
            ],
        ], 402),
    ]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
})->with([
    'insufficient credits' => ['Insufficient credits. Add more credits at https://openrouter.ai/credits'],
    'credit balance' => ['Your credit balance is too low to complete this request.'],
])->throws(InsufficientCreditsException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake(['openrouter.ai/*' => Http::response([
        'error' => [
            'type' => 'invalid_request_error',
            'message' => 'The model does not exist.',
        ],
    ])]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');
})->throws(AiException::class, 'OpenRouter Error');
