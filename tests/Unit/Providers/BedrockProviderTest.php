<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Providers\BedrockProvider;

beforeEach(function (): void {
    $this->dispatcher = Mockery::mock(Dispatcher::class);
});

afterEach(function (): void {
    Mockery::close();
});

test('can be instantiated with config', function (): void {
    $config = [
        'driver' => 'bedrock',
        'name' => 'bedrock',
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'region' => 'us-east-1',
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);

    expect($provider)->toBeInstanceOf(BedrockProvider::class);
});

test('returns iam credentials', function (): void {
    $config = [
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'session_token' => 'test-session',
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $credentials = $provider->providerCredentials();

    expect($credentials)
        ->toHaveKey('access_key_id')
        ->toHaveKey('secret_access_key')
        ->toHaveKey('session_token');

    expect($credentials['access_key_id'])->toBe('test-key')
        ->and($credentials['secret_access_key'])->toBe('test-secret')
        ->and($credentials['session_token'])->toBe('test-session');
});

test('filters out empty credential values', function (): void {
    $config = [
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'session_token' => null,
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $credentials = $provider->providerCredentials();

    expect($credentials)
        ->toHaveKey('access_key_id')
        ->toHaveKey('secret_access_key')
        ->not->toHaveKey('session_token');
});

test('returns additional configuration with region', function (): void {
    $config = [
        'region' => 'us-west-2',
        'use_default_credential_provider' => true,
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    expect($additionalConfig)
        ->toHaveKey('region')
        ->toHaveKey('use_default_credential_provider');

    expect($additionalConfig['region'])->toBe('us-west-2')
        ->and($additionalConfig['use_default_credential_provider'])->toBeTrue();
});

test('preserves false value for use default credential provider', function (): void {
    $config = [
        'region' => 'us-east-1',
        'use_default_credential_provider' => false,
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    expect($additionalConfig)
        ->toHaveKey('use_default_credential_provider');

    expect($additionalConfig['use_default_credential_provider'])->toBeFalse();
});

test('returns bearer token credential when provided', function (): void {
    $config = [
        'key' => 'bedrock-bearer-token',
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $credentials = $provider->providerCredentials();

    expect($credentials)->toHaveKey('key')
        ->and($credentials['key'])->toBe('bedrock-bearer-token');
});

test('defaults to us east 1 region when not specified', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    expect($additionalConfig)->toHaveKey('region')
        ->and($additionalConfig['region'])->toBe('us-east-1');
});

test('returns default text model', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultTextModel())->toBe('us.anthropic.claude-sonnet-4-5-20250929-v1:0');
});

test('returns cheapest text model', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->cheapestTextModel())->toBe('us.anthropic.claude-haiku-4-5-20251001-v1:0');
});

test('returns smartest text model', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->smartestTextModel())->toBe('us.anthropic.claude-opus-4-6-v1');
});

test('allows custom text models in config', function (): void {
    $config = [
        'models' => [
            'text' => [
                'default' => 'custom-model',
                'cheapest' => 'custom-cheapest',
                'smartest' => 'custom-smartest',
            ],
        ],
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);

    expect($provider->defaultTextModel())->toBe('custom-model')
        ->and($provider->cheapestTextModel())->toBe('custom-cheapest')
        ->and($provider->smartestTextModel())->toBe('custom-smartest');
});

test('returns default embeddings model', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultEmbeddingsModel())->toBe('amazon.titan-embed-text-v2:0');
});

test('returns default embeddings dimensions', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultEmbeddingsDimensions())->toBe(1024);
});

test('allows custom embeddings config', function (): void {
    $config = [
        'models' => [
            'embeddings' => [
                'default' => 'custom-embed-model',
                'dimensions' => 1536,
            ],
        ],
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);

    expect($provider->defaultEmbeddingsModel())->toBe('custom-embed-model')
        ->and($provider->defaultEmbeddingsDimensions())->toBe(1536);
});

test('returns default image model', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultImageModel())->toBe('amazon.nova-canvas-v1:0');
});

test('returns default image options', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);
    $options = $provider->defaultImageOptions();

    expect($options)
        ->toHaveKey('quality')
        ->toHaveKey('size');

    expect($options['quality'])->toBe('standard')
        ->and($options['size'])->toBe('1024x1024');
});

test('converts size ratios to dimensions for images', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultImageOptions('1:1')['size'])->toBe('1024x1024')
        ->and($provider->defaultImageOptions('2:3')['size'])->toBe('768x1152')
        ->and($provider->defaultImageOptions('3:2')['size'])->toBe('1152x768');
});

test('normalizes canonical quality values to bedrock values', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->defaultImageOptions(quality: 'low')['quality'])->toBe('standard')
        ->and($provider->defaultImageOptions(quality: 'medium')['quality'])->toBe('standard')
        ->and($provider->defaultImageOptions(quality: 'high')['quality'])->toBe('premium')
        ->and($provider->defaultImageOptions(quality: 'standard')['quality'])->toBe('standard')
        ->and($provider->defaultImageOptions(quality: 'premium')['quality'])->toBe('premium');
});

test('creates text gateway', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->textGateway())->toBeInstanceOf(BedrockTextGateway::class);
});

test('creates embedding gateway', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->embeddingGateway())->toBeInstanceOf(BedrockTextGateway::class);
});

test('creates image gateway', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    expect($provider->imageGateway())->toBeInstanceOf(BedrockImageGateway::class);
});

test('reuses gateway instances', function (): void {
    $provider = new BedrockProvider([], $this->dispatcher);

    $gateway1 = $provider->textGateway();
    $gateway2 = $provider->textGateway();

    expect($gateway1)->toBe($gateway2);
});

test('returns assume role configuration when provided', function () {
    $config = [
        'region' => 'us-west-2',
        'assume_role' => [
            'arn' => 'arn:aws:iam::123456789012:role/test-role',
            'session_name' => 'my-session',
            'duration_seconds' => 900,
            'external_id' => 'ext-123',
        ],
    ];

    $provider = new BedrockProvider($config, $this->dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    expect($additionalConfig['assume_role'])->toBe([
        'arn' => 'arn:aws:iam::123456789012:role/test-role',
        'session_name' => 'my-session',
        'duration_seconds' => 900,
        'external_id' => 'ext-123',
    ]);
});

test('assume role configuration defaults to null when not set', function () {
    $provider = new BedrockProvider([], $this->dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    expect($additionalConfig['assume_role'])->toBe([
        'arn' => null,
        'session_name' => null,
        'duration_seconds' => null,
        'external_id' => null,
    ]);
});
