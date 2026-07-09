<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

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

test('overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 503,
                'message' => 'The model is overloaded. Please try again later.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderOverloadedException::class);

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

test('connection failure throws a failoverable provider connection exception', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderConnectionException::class);

test('connection failure fails over to the next provider', function () {
    config([
        'ai.providers.primary' => ['driver' => 'gemini', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'gemini', 'key' => 'test-key'],
    ]);

    $backup = $this->fakeTextResponse('Recovered on the backup provider');

    $attempts = 0;

    Http::fake(function () use (&$attempts, $backup) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection refused');
        }

        return $backup;
    });

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Recovered on the backup provider');
});
