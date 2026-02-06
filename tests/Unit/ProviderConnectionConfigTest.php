<?php

namespace Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use PHPUnit\Framework\TestCase;

class ProviderConnectionConfigTest extends TestCase
{
    public function test_provider_returns_connection_config_with_url(): void
    {
        $config = [
            'name' => 'anthropic',
            'driver' => 'anthropic',
            'key' => 'test-key',
            'url' => 'https://custom.litellm.com/v1',
            'version' => '2024-01-01',
        ];

        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $connectionConfig = $provider->connectionConfig();

        $this->assertEquals('https://custom.litellm.com/v1', $connectionConfig['url']);
        $this->assertEquals('2024-01-01', $connectionConfig['version']);
    }

    public function test_provider_returns_empty_connection_config_when_not_configured(): void
    {
        $config = [
            'name' => 'anthropic',
            'driver' => 'anthropic',
            'key' => 'test-key',
        ];

        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $connectionConfig = $provider->connectionConfig();

        $this->assertEmpty($connectionConfig);
    }

    public function test_openai_provider_returns_organization_and_project(): void
    {
        $config = [
            'name' => 'openai',
            'driver' => 'openai',
            'key' => 'test-key',
            'url' => 'https://api.openai.com/v1',
            'organization' => 'org-123',
            'project' => 'proj-456',
        ];

        $provider = new OpenAiProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $connectionConfig = $provider->connectionConfig();

        $this->assertEquals('https://api.openai.com/v1', $connectionConfig['url']);
        $this->assertEquals('org-123', $connectionConfig['organization']);
        $this->assertEquals('proj-456', $connectionConfig['project']);
    }

    public function test_provider_credentials_returns_key(): void
    {
        $config = [
            'name' => 'anthropic',
            'driver' => 'anthropic',
            'key' => 'test-api-key',
        ];

        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $credentials = $provider->providerCredentials();

        $this->assertEquals('test-api-key', $credentials['key']);
    }

    public function test_provider_credentials_handles_missing_key(): void
    {
        $config = [
            'name' => 'ollama',
            'driver' => 'ollama',
            'url' => 'http://localhost:11434/v1',
        ];

        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $credentials = $provider->providerCredentials();

        $this->assertNull($credentials['key']);
    }

    public function test_connection_config_filters_null_values(): void
    {
        $config = [
            'name' => 'openai',
            'driver' => 'openai',
            'key' => 'test-key',
            'url' => 'https://custom.com/v1',
            // organization and project not set
        ];

        $provider = new OpenAiProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $connectionConfig = $provider->connectionConfig();

        $this->assertArrayHasKey('url', $connectionConfig);
        $this->assertArrayNotHasKey('organization', $connectionConfig);
        $this->assertArrayNotHasKey('project', $connectionConfig);
    }
}
