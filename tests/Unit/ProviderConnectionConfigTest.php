<?php

namespace Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\CohereProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderConnectionConfigTest extends TestCase
{
    #[DataProvider('providerConfigDataProvider')]
    public function test_provider_returns_additional_config(array $config, array $expected): void
    {
        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $additionalConfig = $provider->additionalConfiguration();

        $this->assertEquals($expected, $additionalConfig);
    }

    public static function providerConfigDataProvider(): array
    {
        return [
            'with custom URL' => [
                'config' => [
                    'name' => 'anthropic',
                    'driver' => 'anthropic',
                    'key' => 'test-key',
                    'url' => 'https://custom.litellm.com/v1',
                ],
                'expected' => [
                    'url' => 'https://custom.litellm.com/v1',
                ],
            ],
            'without custom URL' => [
                'config' => [
                    'name' => 'anthropic',
                    'driver' => 'anthropic',
                    'key' => 'test-key',
                ],
                'expected' => [],
            ],
            'with multiple custom params' => [
                'config' => [
                    'name' => 'anthropic',
                    'driver' => 'anthropic',
                    'key' => 'test-key',
                    'url' => 'https://custom-litellm.com/v1',
                    'version' => '2024-01-01',
                    'custom_param' => 'custom_value',
                ],
                'expected' => [
                    'url' => 'https://custom-litellm.com/v1',
                    'version' => '2024-01-01',
                    'custom_param' => 'custom_value',
                ],
            ],
        ];
    }

    #[DataProvider('providerCredentialsDataProvider')]
    public function test_provider_credentials(array $config, ?string $expectedKey): void
    {
        $provider = new AnthropicProvider(
            $this->createMock(PrismGateway::class),
            $config,
            $this->createMock(Dispatcher::class)
        );

        $credentials = $provider->providerCredentials();

        $this->assertEquals($expectedKey, $credentials['key']);
    }

    public static function providerCredentialsDataProvider(): array
    {
        return [
            'with API key' => [
                'config' => [
                    'name' => 'anthropic',
                    'driver' => 'anthropic',
                    'key' => 'test-api-key',
                ],
                'expectedKey' => 'test-api-key',
            ],
            'without API key' => [
                'config' => [
                    'name' => 'anthropic',
                    'driver' => 'anthropic',
                    'url' => 'https://custom-proxy.com/v1',
                ],
                'expectedKey' => null,
            ],
        ];
    }

    /**
     * Test that Cohere provider returns additional configuration correctly.
     *
     * Cohere uses a different implementation (CohereGateway vs PrismGateway)
     * but still extends the base Provider class. This test verifies that the
     * base additionalConfiguration() method works for non-Prism providers too.
     */
    #[DataProvider('cohereProviderConfigDataProvider')]
    public function test_cohere_provider_returns_additional_config(array $config, array $expected): void
    {
        $provider = new CohereProvider(
            $config,
            $this->createMock(Dispatcher::class)
        );

        $additionalConfig = $provider->additionalConfiguration();

        $this->assertEquals($expected, $additionalConfig);
    }

    public static function cohereProviderConfigDataProvider(): array
    {
        return [
            'with custom URL' => [
                'config' => [
                    'name' => 'cohere',
                    'driver' => 'cohere',
                    'key' => 'test-key',
                    'url' => 'https://custom.litellm.com/v1',
                ],
                'expected' => [
                    'url' => 'https://custom.litellm.com/v1',
                ],
            ],
            'without custom URL' => [
                'config' => [
                    'name' => 'cohere',
                    'driver' => 'cohere',
                    'key' => 'test-key',
                ],
                'expected' => [],
            ],
            'with multiple custom params' => [
                'config' => [
                    'name' => 'cohere',
                    'driver' => 'cohere',
                    'key' => 'test-key',
                    'url' => 'https://custom-litellm.com/v1',
                    'custom_param' => 'custom_value',
                ],
                'expected' => [
                    'url' => 'https://custom-litellm.com/v1',
                    'custom_param' => 'custom_value',
                ],
            ],
        ];
    }
}
