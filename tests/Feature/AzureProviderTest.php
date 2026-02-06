<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\AzureProvider;
use Tests\TestCase;

class AzureProviderTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('ai.providers.azure', [
            'driver' => 'azure',
            'name' => 'azure',
            'key' => 'test-azure-api-key',
            'url' => 'https://my-resource.openai.azure.com/openai/deployments/gpt-4o',
            'api_version' => '2024-12-01-preview',
        ]);
    }

    public function test_can_resolve_azure_provider(): void
    {
        $provider = Ai::textProvider('azure');

        $this->assertInstanceOf(AzureProvider::class, $provider);
    }

    public function test_azure_provider_has_correct_credentials(): void
    {
        $provider = Ai::textProvider('azure');

        $this->assertEquals([
            'key' => 'test-azure-api-key',
            'url' => 'https://my-resource.openai.azure.com/openai/deployments/gpt-4o',
            'api_version' => '2024-12-01-preview',
        ], $provider->providerCredentials());
    }

    public function test_azure_provider_sends_correct_headers_and_query_params(): void
    {
        Http::fake([
            'my-resource.openai.azure.com/*' => Http::response([
                'id' => 'resp-123',
                'object' => 'response',
                'model' => 'gpt-4o',
                'output' => [
                    [
                        'type' => 'message',
                        'status' => 'completed',
                        'role' => 'assistant',
                        'content' => [
                            [
                                'type' => 'output_text',
                                'text' => 'Hello from Azure!',
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ],
            ]),
        ]);

        $response = (new \Tests\Feature\Agents\AssistantAgent)->prompt(
            'Say hello',
            provider: 'azure',
            model: 'gpt-4o',
        );

        $this->assertEquals('Hello from Azure!', $response->text);

        Http::assertSent(function ($request) {
            return $request->hasHeader('api-key', 'test-azure-api-key')
                && ! $request->hasHeader('Authorization')
                && str_contains($request->url(), 'api-version=2024-12-01-preview')
                && str_contains($request->url(), 'my-resource.openai.azure.com');
        });
    }

    public function test_azure_provider_is_also_an_embedding_provider(): void
    {
        $provider = Ai::embeddingProvider('azure');

        $this->assertInstanceOf(AzureProvider::class, $provider);
        $this->assertEquals('text-embedding-3-small', $provider->defaultEmbeddingsModel());
        $this->assertEquals(1536, $provider->defaultEmbeddingsDimensions());
    }
}
