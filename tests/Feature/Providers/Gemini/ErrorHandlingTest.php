<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

test('http error response throws request exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'Invalid value at contents',
                'status' => 'INVALID_ARGUMENT',
            ],
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Resource has been exhausted',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RateLimitedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(AiException::class, 'Gemini Error');
