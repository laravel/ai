<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\ProviderRequestException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('http error response throws request exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'model not found',
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(ProviderRequestException::class);

test('rate limit response throws rate limited exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'rate limit exceeded',
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'server overloaded',
        ], 503),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function () {
    Http::fake([
        'localhost:11434/*' => Http::response([
            'error' => 'model "unknown-model" not found',
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'ollama',
    );
})->throws(AiException::class, 'Ollama Error');
