<?php

namespace Tests\Unit\Providers;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Tests\TestCase;

class OpenAiCompatibleProviderTest extends TestCase
{
    public function test_can_resolve_openai_compatible_provider()
    {
        Config::set('ai.providers.my-custom-provider', [
            'driver' => 'openai-compatible',
            'key' => 'test-key',
            'url' => 'https://api.example.com/v1',
        ]);

        $provider = Ai::textProvider('my-custom-provider');

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
    }

    public function test_uses_configured_models()
    {
        Config::set('ai.providers.custom-models', [
            'driver' => 'openai-compatible',
            'models' => [
                'default' => 'custom-gpt-4',
                'cheapest' => 'custom-gpt-3.5',
                'smartest' => 'custom-gpt-5',
                'embeddings' => 'custom-embedding-model',
                'embedding_dimensions' => 1024,
            ],
        ]);

        $provider = Ai::textProvider('custom-models');

        $this->assertEquals('custom-gpt-4', $provider->defaultTextModel());
        $this->assertEquals('custom-gpt-3.5', $provider->cheapestTextModel());
        $this->assertEquals('custom-gpt-5', $provider->smartestTextModel());
        $this->assertEquals('custom-embedding-model', $provider->defaultEmbeddingsModel());
        $this->assertEquals(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function test_uses_fallback_models_when_not_configured()
    {
        Config::set('ai.providers.fallback-models', [
            'driver' => 'openai-compatible',
        ]);

        $provider = Ai::textProvider('fallback-models');

        $this->assertEquals('gpt-4o', $provider->defaultTextModel());
        $this->assertEquals('gpt-4o', $provider->cheapestTextModel()); // Falls back to default
        $this->assertEquals('gpt-4o', $provider->smartestTextModel()); // Falls back to default
        $this->assertEquals('text-embedding-3-small', $provider->defaultEmbeddingsModel());
        $this->assertEquals(1536, $provider->defaultEmbeddingsDimensions());
    }

    public function test_can_resolve_multiple_instances()
    {
        Config::set('ai.providers.provider1', [
            'driver' => 'openai-compatible',
            'models' => ['default' => 'model-1'],
        ]);

        Config::set('ai.providers.provider2', [
            'driver' => 'openai-compatible',
            'models' => ['default' => 'model-2'],
        ]);

        $provider1 = Ai::textProvider('provider1');
        $provider2 = Ai::textProvider('provider2');

        $this->assertNotSame($provider1, $provider2);
        $this->assertEquals('model-1', $provider1->defaultTextModel());
        $this->assertEquals('model-2', $provider2->defaultTextModel());
    }
}
