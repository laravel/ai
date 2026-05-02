<?php

namespace Tests\Unit\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Providers\BedrockProvider;
use Mockery;
use PHPUnit\Framework\TestCase;

uses(TestCase::class);

afterEach(fn () => Mockery::close());

function bedrockProvider(array $config, Dispatcher $dispatcher): BedrockProvider
{
    return new BedrockProvider($config, $dispatcher);
}

test('can be instantiated with config', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'driver' => 'bedrock',
        'name' => 'bedrock',
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'region' => 'us-east-1',
    ], $dispatcher);

    $this->assertInstanceOf(BedrockProvider::class, $provider);
});

test('returns iam credentials', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'session_token' => 'test-session',
    ], $dispatcher);

    $credentials = $provider->providerCredentials();

    $this->assertArrayHasKey('access_key_id', $credentials);
    $this->assertArrayHasKey('secret_access_key', $credentials);
    $this->assertArrayHasKey('session_token', $credentials);
    $this->assertEquals('test-key', $credentials['access_key_id']);
    $this->assertEquals('test-secret', $credentials['secret_access_key']);
    $this->assertEquals('test-session', $credentials['session_token']);
});

test('filters out empty credential values', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'access_key_id' => 'test-key',
        'secret_access_key' => 'test-secret',
        'session_token' => null,
    ], $dispatcher);

    $credentials = $provider->providerCredentials();

    $this->assertArrayHasKey('access_key_id', $credentials);
    $this->assertArrayHasKey('secret_access_key', $credentials);
    $this->assertArrayNotHasKey('session_token', $credentials);
});

test('returns additional configuration with region', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'region' => 'us-west-2',
        'use_default_credential_provider' => true,
    ], $dispatcher);

    $additionalConfig = $provider->additionalConfiguration();

    $this->assertArrayHasKey('region', $additionalConfig);
    $this->assertArrayHasKey('use_default_credential_provider', $additionalConfig);
    $this->assertEquals('us-west-2', $additionalConfig['region']);
    $this->assertTrue($additionalConfig['use_default_credential_provider']);
});

test('preserves false value for use_default_credential_provider', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'region' => 'us-east-1',
        'use_default_credential_provider' => false,
    ], $dispatcher);

    $additionalConfig = $provider->additionalConfiguration();

    $this->assertArrayHasKey('use_default_credential_provider', $additionalConfig);
    $this->assertFalse($additionalConfig['use_default_credential_provider']);
});

test('returns bearer token credential when provided', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'key' => 'bedrock-bearer-token',
    ], $dispatcher);

    $credentials = $provider->providerCredentials();

    $this->assertArrayHasKey('key', $credentials);
    $this->assertEquals('bedrock-bearer-token', $credentials['key']);
});

test('defaults to us_east_1 region when not specified', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);
    $additionalConfig = $provider->additionalConfiguration();

    $this->assertArrayHasKey('region', $additionalConfig);
    $this->assertEquals('us-east-1', $additionalConfig['region']);
});

test('returns default text model', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('us.anthropic.claude-sonnet-4-5-20250929-v1:0', $provider->defaultTextModel());
});

test('returns cheapest text model', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('us.anthropic.claude-haiku-4-5-20251001-v1:0', $provider->cheapestTextModel());
});

test('returns smartest text model', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('us.anthropic.claude-opus-4-6-v1', $provider->smartestTextModel());
});

test('allows custom text models in config', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'models' => [
            'text' => [
                'default' => 'custom-model',
                'cheapest' => 'custom-cheapest',
                'smartest' => 'custom-smartest',
            ],
        ],
    ], $dispatcher);

    $this->assertEquals('custom-model', $provider->defaultTextModel());
    $this->assertEquals('custom-cheapest', $provider->cheapestTextModel());
    $this->assertEquals('custom-smartest', $provider->smartestTextModel());
});

test('returns default embeddings model', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('amazon.titan-embed-text-v2:0', $provider->defaultEmbeddingsModel());
});

test('returns default embeddings dimensions', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals(1024, $provider->defaultEmbeddingsDimensions());
});

test('allows custom embeddings config', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([
        'models' => [
            'embeddings' => [
                'default' => 'custom-embed-model',
                'dimensions' => 1536,
            ],
        ],
    ], $dispatcher);

    $this->assertEquals('custom-embed-model', $provider->defaultEmbeddingsModel());
    $this->assertEquals(1536, $provider->defaultEmbeddingsDimensions());
});

test('returns default image model', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('amazon.nova-canvas-v1:0', $provider->defaultImageModel());
});

test('returns default image options', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);
    $options = $provider->defaultImageOptions();

    $this->assertArrayHasKey('quality', $options);
    $this->assertArrayHasKey('size', $options);
    $this->assertEquals('standard', $options['quality']);
    $this->assertEquals('1024x1024', $options['size']);
});

test('converts size ratios to dimensions for images', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('1024x1024', $provider->defaultImageOptions('1:1')['size']);
    $this->assertEquals('768x1152', $provider->defaultImageOptions('2:3')['size']);
    $this->assertEquals('1152x768', $provider->defaultImageOptions('3:2')['size']);
});

test('normalizes canonical quality values to bedrock values', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $this->assertEquals('standard', $provider->defaultImageOptions(quality: 'low')['quality']);
    $this->assertEquals('standard', $provider->defaultImageOptions(quality: 'medium')['quality']);
    $this->assertEquals('premium', $provider->defaultImageOptions(quality: 'high')['quality']);
    $this->assertEquals('standard', $provider->defaultImageOptions(quality: 'standard')['quality']);
    $this->assertEquals('premium', $provider->defaultImageOptions(quality: 'premium')['quality']);
});

test('creates text gateway', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);
    $gateway = $provider->textGateway();

    $this->assertInstanceOf(BedrockTextGateway::class, $gateway);
});

test('creates embedding gateway', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);
    $gateway = $provider->embeddingGateway();

    $this->assertInstanceOf(BedrockTextGateway::class, $gateway);
});

test('creates image gateway', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);
    $gateway = $provider->imageGateway();

    $this->assertInstanceOf(BedrockImageGateway::class, $gateway);
});

test('reuses gateway instances', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $provider = bedrockProvider([], $dispatcher);

    $gateway1 = $provider->textGateway();
    $gateway2 = $provider->textGateway();

    $this->assertSame($gateway1, $gateway2);
});
