<?php

namespace Tests\Unit\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTranscriptionGateway;
use Laravel\Ai\Providers\BedrockProvider;
use Mockery;
use PHPUnit\Framework\TestCase;

class BedrockProviderTest extends TestCase
{
    protected $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_be_instantiated_with_config(): void
    {
        $config = [
            'driver' => 'bedrock',
            'name' => 'bedrock',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'region' => 'us-east-1',
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);

        $this->assertInstanceOf(BedrockProvider::class, $provider);
    }

    public function test_returns_iam_credentials(): void
    {
        $config = [
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'session_token' => 'test-session',
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $credentials = $provider->providerCredentials();

        $this->assertArrayHasKey('access_key_id', $credentials);
        $this->assertArrayHasKey('secret_access_key', $credentials);
        $this->assertArrayHasKey('session_token', $credentials);
        $this->assertEquals('test-key', $credentials['access_key_id']);
        $this->assertEquals('test-secret', $credentials['secret_access_key']);
        $this->assertEquals('test-session', $credentials['session_token']);
    }

    public function test_filters_out_empty_credential_values(): void
    {
        $config = [
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
            'session_token' => null,
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $credentials = $provider->providerCredentials();

        $this->assertArrayHasKey('access_key_id', $credentials);
        $this->assertArrayHasKey('secret_access_key', $credentials);
        $this->assertArrayNotHasKey('session_token', $credentials);
    }

    public function test_returns_additional_configuration_with_region(): void
    {
        $config = [
            'region' => 'us-west-2',
            'use_default_credential_provider' => true,
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertArrayHasKey('region', $additionalConfig);
        $this->assertArrayHasKey('use_default_credential_provider', $additionalConfig);
        $this->assertEquals('us-west-2', $additionalConfig['region']);
        $this->assertTrue($additionalConfig['use_default_credential_provider']);
    }

    public function test_defaults_to_us_east_1_region_when_not_specified(): void
    {
        $config = [];

        $provider = new BedrockProvider($config, $this->dispatcher);
        $additionalConfig = $provider->additionalConfiguration();

        $this->assertArrayHasKey('region', $additionalConfig);
        $this->assertEquals('us-east-1', $additionalConfig['region']);
    }

    public function test_returns_default_text_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('us.anthropic.claude-sonnet-4-5-20250929-v1:0', $provider->defaultTextModel());
    }

    public function test_returns_cheapest_text_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('us.anthropic.claude-haiku-4-5-20250929-v1:0', $provider->cheapestTextModel());
    }

    public function test_returns_smartest_text_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('us.anthropic.claude-opus-4-6-20250929-v1:0', $provider->smartestTextModel());
    }

    public function test_allows_custom_text_models_in_config(): void
    {
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

        $this->assertEquals('custom-model', $provider->defaultTextModel());
        $this->assertEquals('custom-cheapest', $provider->cheapestTextModel());
        $this->assertEquals('custom-smartest', $provider->smartestTextModel());
    }

    public function test_returns_default_embeddings_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('amazon.titan-embed-text-v2:0', $provider->defaultEmbeddingsModel());
    }

    public function test_returns_default_embeddings_dimensions(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals(1024, $provider->defaultEmbeddingsDimensions());
    }

    public function test_allows_custom_embeddings_config(): void
    {
        $config = [
            'models' => [
                'embeddings' => [
                    'default' => 'custom-embed-model',
                    'dimensions' => 1536,
                ],
            ],
        ];

        $provider = new BedrockProvider($config, $this->dispatcher);

        $this->assertEquals('custom-embed-model', $provider->defaultEmbeddingsModel());
        $this->assertEquals(1536, $provider->defaultEmbeddingsDimensions());
    }

    public function test_returns_default_image_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('amazon.nova-canvas-v1:0', $provider->defaultImageModel());
    }

    public function test_returns_default_image_options(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $options = $provider->defaultImageOptions();

        $this->assertArrayHasKey('quality', $options);
        $this->assertArrayHasKey('size', $options);
        $this->assertEquals('standard', $options['quality']);
        $this->assertEquals('1024x1024', $options['size']);
    }

    public function test_converts_size_ratios_to_dimensions_for_images(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('1024x1024', $provider->defaultImageOptions('1:1')['size']);
        $this->assertEquals('768x1152', $provider->defaultImageOptions('2:3')['size']);
        $this->assertEquals('1152x768', $provider->defaultImageOptions('3:2')['size']);
    }

    public function test_returns_default_transcription_model(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $this->assertEquals('amazon.nova-sonic-v1:0', $provider->defaultTranscriptionModel());
    }

    public function test_creates_text_gateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $gateway = $provider->textGateway();

        $this->assertInstanceOf(BedrockTextGateway::class, $gateway);
    }

    public function test_creates_embedding_gateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $gateway = $provider->embeddingGateway();

        $this->assertInstanceOf(BedrockTextGateway::class, $gateway);
    }

    public function test_creates_image_gateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $gateway = $provider->imageGateway();

        $this->assertInstanceOf(BedrockImageGateway::class, $gateway);
    }

    public function test_creates_transcription_gateway(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);
        $gateway = $provider->transcriptionGateway();

        $this->assertInstanceOf(BedrockTranscriptionGateway::class, $gateway);
    }

    public function test_reuses_gateway_instances(): void
    {
        $provider = new BedrockProvider([], $this->dispatcher);

        $gateway1 = $provider->textGateway();
        $gateway2 = $provider->textGateway();

        $this->assertSame($gateway1, $gateway2);
    }
}
