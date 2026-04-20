<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\BedrockProvider;

beforeEach(function () {
    config(['ai.providers.bedrock' => [
        ...config('ai.providers.bedrock'),
        'name' => 'bedrock',
        'key' => 'AKIA_TEST_KEY',
        'secret' => 'super-secret',
        'session_token' => 'session-token',
        'region' => 'us-west-2',
        'url' => 'https://bedrock-runtime.us-west-2.amazonaws.com',
        'use_default_credential_provider' => false,
    ]]);
});

test('bedrock provider is resolved for text embeddings and images', function () {
    expect(Ai::textProvider('bedrock'))->toBeInstanceOf(BedrockProvider::class)
        ->and(Ai::embeddingProvider('bedrock'))->toBeInstanceOf(BedrockProvider::class)
        ->and(Ai::imageProvider('bedrock'))->toBeInstanceOf(BedrockProvider::class);
});

test('bedrock provider credentials include aws keys when provided', function () {
    $provider = Ai::textProvider('bedrock');

    expect($provider->providerCredentials())->toBe([
        'key' => 'AKIA_TEST_KEY',
        'secret' => 'super-secret',
        'session_token' => 'session-token',
    ]);
});

test('bedrock provider additional config includes region endpoint and credential strategy', function () {
    $provider = Ai::textProvider('bedrock');

    expect($provider->additionalConfiguration())->toBe([
        'region' => 'us-west-2',
        'url' => 'https://bedrock-runtime.us-west-2.amazonaws.com',
        'use_default_credential_provider' => false,
    ]);
});

test('bedrock provider supports default credential chain without static keys', function () {
    config(['ai.providers.bedrock' => [
        ...config('ai.providers.bedrock'),
        'key' => null,
        'secret' => null,
        'session_token' => null,
        'use_default_credential_provider' => true,
    ]]);

    $provider = Ai::textProvider('bedrock');

    expect($provider->providerCredentials())->toBe([])
        ->and($provider->additionalConfiguration()['use_default_credential_provider'])->toBeTrue();
});

test('bedrock provider model defaults can be overridden by config', function () {
    config(['ai.providers.bedrock.models' => [
        'text' => [
            'default' => 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            'cheapest' => 'amazon.nova-lite-v1:0',
            'smartest' => 'anthropic.claude-opus-4-1-20250805-v1:0',
        ],
        'image' => [
            'default' => 'amazon.nova-canvas-v1:0',
        ],
        'embeddings' => [
            'default' => 'amazon.titan-embed-text-v2:0',
            'dimensions' => 512,
        ],
    ]]);

    $provider = Ai::textProvider('bedrock');

    expect($provider->defaultTextModel())->toBe('anthropic.claude-3-5-sonnet-20241022-v2:0')
        ->and($provider->cheapestTextModel())->toBe('amazon.nova-lite-v1:0')
        ->and($provider->smartestTextModel())->toBe('anthropic.claude-opus-4-1-20250805-v1:0')
        ->and($provider->defaultImageModel())->toBe('amazon.nova-canvas-v1:0')
        ->and($provider->defaultEmbeddingsModel())->toBe('amazon.titan-embed-text-v2:0')
        ->and($provider->defaultEmbeddingsDimensions())->toBe(512);
});
