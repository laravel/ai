<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Providers\Anthropic\AnthropicHelpers;

uses(AnthropicHelpers::class);

test('http error response throws request exception', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'max_tokens: must be at least 1',
            ],
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );
})->throws(RateLimitedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );
})->throws(AiException::class, 'api_error');
