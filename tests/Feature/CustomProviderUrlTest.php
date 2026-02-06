<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\CohereProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class CustomProviderUrlTest extends TestCase
{
    /**
     * Test custom URL support for Prism-based text providers.
     *
     * Prism providers (Anthropic, OpenAI, Gemini, etc.) use PrismGateway which
     * passes additionalConfiguration() to the Prism library. This test verifies
     * that custom URLs are properly passed through this pipeline.
     */
    public function test_provider_can_be_configured_with_custom_url(): void
    {
        config([
            'ai.providers.anthropic' => [
                'driver' => 'anthropic',
                'key' => 'test-key',
                'url' => 'https://litellm.company.com/v1',
            ],
        ]);

        $provider = Ai::textProvider('anthropic');

        $this->assertInstanceOf(AnthropicProvider::class, $provider);

        $additionalConfig = $provider->additionalConfiguration();
        $this->assertEquals('https://litellm.company.com/v1', $additionalConfig['url']);
    }

    public function test_provider_works_without_custom_url(): void
    {
        config([
            'ai.providers.anthropic' => [
                'driver' => 'anthropic',
                'key' => 'test-key',
            ],
        ]);

        $provider = Ai::textProvider('anthropic');

        $this->assertInstanceOf(AnthropicProvider::class, $provider);

        $additionalConfig = $provider->additionalConfiguration();
        $this->assertEmpty($additionalConfig);
    }

    public function test_multiple_provider_instances_with_different_urls(): void
    {
        config([
            'ai.providers.anthropic' => [
                'driver' => 'anthropic',
                'key' => 'direct-api-key',
            ],
            'ai.providers.anthropic-litellm' => [
                'driver' => 'anthropic',
                'key' => 'litellm-key',
                'url' => 'https://litellm.company.com/v1',
            ],
        ]);

        $directProvider = Ai::textProvider('anthropic');
        $litellmProvider = Ai::textProvider('anthropic-litellm');

        $this->assertEmpty($directProvider->additionalConfiguration());

        $litellmConfig = $litellmProvider->additionalConfiguration();
        $this->assertEquals('https://litellm.company.com/v1', $litellmConfig['url']);
    }

    /**
     * Test custom URL support for Cohere (embeddings/reranking provider).
     *
     * Cohere uses CohereGateway (direct HTTP client) instead of PrismGateway,
     * requiring a different implementation. We test this separately to verify
     * that BOTH code paths (Prism-based and direct HTTP) support custom URLs.
     * This is NOT duplication - it's testing two different implementations.
     */
    public function test_cohere_provider_can_be_configured_with_custom_url(): void
    {
        config([
            'ai.providers.cohere' => [
                'driver' => 'cohere',
                'key' => 'test-key',
                'url' => 'https://litellm.company.com/v1',
            ],
        ]);

        $provider = Ai::embeddingProvider('cohere');

        $this->assertInstanceOf(CohereProvider::class, $provider);

        $additionalConfig = $provider->additionalConfiguration();
        $this->assertEquals('https://litellm.company.com/v1', $additionalConfig['url']);
    }

    public function test_cohere_provider_works_without_custom_url(): void
    {
        config([
            'ai.providers.cohere' => [
                'driver' => 'cohere',
                'key' => 'test-key',
            ],
        ]);

        $provider = Ai::embeddingProvider('cohere');

        $this->assertInstanceOf(CohereProvider::class, $provider);

        $additionalConfig = $provider->additionalConfiguration();
        $this->assertEmpty($additionalConfig);
    }
}
