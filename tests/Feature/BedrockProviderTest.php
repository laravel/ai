<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Tests\TestCase;

class BedrockProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('ai.providers.bedrock', [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key' => 'test-access-key',
            'secret_key' => 'test-secret-key',
            'session_token' => 'test-session-token',
            'region' => 'us-west-2',
        ]);
    }

    public function test_provider_credentials_returns_access_key(): void
    {
        $provider = Ai::textProvider('bedrock');

        $credentials = $provider->providerCredentials();

        $this->assertEquals('test-access-key', $credentials['key']);
    }

    public function test_additional_configuration_returns_aws_config(): void
    {
        $provider = Ai::textProvider('bedrock');

        $config = $provider->additionalConfiguration();

        $this->assertEquals('test-secret-key', $config['api_secret']);
        $this->assertEquals('test-session-token', $config['session_token']);
        $this->assertEquals('us-west-2', $config['region']);
        $this->assertArrayNotHasKey('use_default_credential_provider', $config);
    }

    public function test_uses_default_credential_provider_when_keys_are_empty(): void
    {
        $this->app['config']->set('ai.providers.bedrock', [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key' => null,
            'secret_key' => null,
            'region' => 'us-east-1',
        ]);

        $provider = Ai::textProvider('bedrock');
        $config = $provider->additionalConfiguration();

        $this->assertTrue($config['use_default_credential_provider']);
    }

    public function test_does_not_use_default_credential_provider_when_keys_set(): void
    {
        $provider = Ai::textProvider('bedrock');
        $config = $provider->additionalConfiguration();

        $this->assertArrayNotHasKey('use_default_credential_provider', $config);
    }

    public function test_default_text_model(): void
    {
        $provider = Ai::textProvider('bedrock');

        $this->assertEquals('anthropic.claude-sonnet-4-5-20250929-v1:0', $provider->defaultTextModel());
    }

    public function test_cheapest_text_model(): void
    {
        $provider = Ai::textProvider('bedrock');

        $this->assertEquals('anthropic.claude-haiku-4-5-20251001-v1:0', $provider->cheapestTextModel());
    }

    public function test_smartest_text_model(): void
    {
        $provider = Ai::textProvider('bedrock');

        $this->assertEquals('anthropic.claude-opus-4-6-v1:0', $provider->smartestTextModel());
    }

    public function test_default_embeddings_model(): void
    {
        $provider = Ai::embeddingProvider('bedrock');

        $this->assertEquals('amazon.titan-embed-text-v2:0', $provider->defaultEmbeddingsModel());
    }

    public function test_default_embeddings_dimensions(): void
    {
        $provider = Ai::embeddingProvider('bedrock');

        $this->assertEquals(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function test_region_defaults_to_us_east_1(): void
    {
        $this->app['config']->set('ai.providers.bedrock', [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key' => 'key',
            'secret_key' => 'secret',
        ]);

        $provider = Ai::textProvider('bedrock');
        $config = $provider->additionalConfiguration();

        $this->assertEquals('us-east-1', $config['region']);
    }
}
