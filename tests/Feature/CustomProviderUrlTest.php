<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class CustomProviderUrlTest extends TestCase
{
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

        $connectionConfig = $provider->connectionConfig();
        $this->assertEquals('https://litellm.company.com/v1', $connectionConfig['url']);
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

        $connectionConfig = $provider->connectionConfig();
        $this->assertEmpty($connectionConfig);
    }

    public function test_openai_provider_can_be_configured_with_organization_and_project(): void
    {
        config([
            'ai.providers.openai' => [
                'driver' => 'openai',
                'key' => 'test-key',
                'url' => 'https://api.openai.com/v1',
                'organization' => 'org-test-123',
                'project' => 'proj-test-456',
            ],
        ]);

        $provider = Ai::textProvider('openai');

        $this->assertInstanceOf(OpenAiProvider::class, $provider);

        $connectionConfig = $provider->connectionConfig();
        $this->assertEquals('https://api.openai.com/v1', $connectionConfig['url']);
        $this->assertEquals('org-test-123', $connectionConfig['organization']);
        $this->assertEquals('proj-test-456', $connectionConfig['project']);
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

        $this->assertEmpty($directProvider->connectionConfig());

        $litellmConfig = $litellmProvider->connectionConfig();
        $this->assertEquals('https://litellm.company.com/v1', $litellmConfig['url']);
    }

    public function test_anthropic_provider_can_be_configured_with_version(): void
    {
        config([
            'ai.providers.anthropic' => [
                'driver' => 'anthropic',
                'key' => 'test-key',
                'url' => 'https://api.anthropic.com/v1',
                'version' => '2024-06-01',
            ],
        ]);

        $provider = Ai::textProvider('anthropic');

        $connectionConfig = $provider->connectionConfig();
        $this->assertEquals('2024-06-01', $connectionConfig['version']);
    }
}
