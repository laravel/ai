<?php

use Laravel\Ai\Messages\UserMessage;
use Tests\Fixtures\Agents\AssistantAgent;

test('token counting works with anthropic provider', function (string $provider, string $apiKey, string $model) {
    if ($provider !== 'anthropic') {
        $this->markTestSkipped('Anthropic provider required');
    }

    requiresApiKey($apiKey);

    $agent = new AssistantAgent;
    $provider = \Ai::textProvider($provider);

    $tokens = $provider->countTokens(
        model: $model,
        instructions: 'You are a helpful assistant.',
        messages: [
            new UserMessage('What is PHP?'),
        ],
    );

    expect($tokens)->toBeGreaterThan(0)
        ->and($tokens)->toBeLessThan(1000);
})->with('agent-providers');

test('token counting works with openai provider', function (string $provider, string $apiKey, string $model) {
    if ($provider !== 'openai') {
        $this->markTestSkipped('OpenAI provider required');
    }

    requiresApiKey($apiKey);

    $textProvider = \Ai::textProvider($provider);

    $tokens = $textProvider->countTokens(
        model: $model,
        instructions: 'You are a helpful assistant.',
        messages: [
            new UserMessage('What is PHP?'),
        ],
    );

    expect($tokens)->toBeGreaterThan(0)
        ->and($tokens)->toBeLessThan(1000);
})->with('agent-providers');

test('token counting works with gemini provider', function (string $provider, string $apiKey, string $model) {
    if ($provider !== 'gemini') {
        $this->markTestSkipped('Gemini provider required');
    }

    requiresApiKey($apiKey);

    $textProvider = \Ai::textProvider($provider);

    $tokens = $textProvider->countTokens(
        model: $model,
        instructions: 'You are a helpful assistant.',
        messages: [
            new UserMessage('What is PHP?'),
        ],
    );

    expect($tokens)->toBeGreaterThan(0)
        ->and($tokens)->toBeLessThan(1000);
})->with('agent-providers');
