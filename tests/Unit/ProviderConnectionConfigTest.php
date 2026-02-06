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
        ];

        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $connectionConfig = $provider->connectionConfig();

        $this->assertEquals('https://custom.litellm.com/v1', $connectionConfig['url']);
        $this->assertCount(1, $connectionConfig);
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
}
