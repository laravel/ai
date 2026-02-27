<?php

namespace Tests\Feature;

use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\AzureOpenAiProvider;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class AzureDeploymentRoutingTest extends TestCase
{
    public function test_it_routes_text_generation_models_to_deployments()
    {
        config(['ai.default' => 'azure']);
        config(['ai.providers.azure' => [
            'driver' => 'azure',
            'key' => 'test-key',
            'url' => 'https://test.openai.azure.com',
            'deployments' => [
                'gpt-4o' => 'my-gpt4o-deployment',
                'gpt-4o-mini' => 'my-mini-deployment',
            ],
        ]]);

        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello', model: 'gpt-4o');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->model === 'my-gpt4o-deployment';
        });

        (new AssistantAgent)->prompt('Hello', model: 'gpt-4o-mini');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->model === 'my-mini-deployment';
        });

        (new AssistantAgent)->prompt('Hello', model: 'unknown-model');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->model === 'unknown-model';
        });
    }

    public function test_it_routes_embeddings_models_to_deployments()
    {
        config(['ai.providers.azure' => [
            'driver' => 'azure',
            'key' => 'test-key',
            'url' => 'https://test.openai.azure.com',
            'deployments' => [
                'text-embedding-3-small' => 'my-embedding-deployment',
            ],
        ]]);

        /** @var AzureOpenAiProvider $provider */
        $provider = Ai::embeddingProvider('azure');

        // We can't easily check the final call to the gateway without deep mocking,
        // but we can verify the router logic is accessible and works.
        $this->assertEquals('my-embedding-deployment', $provider->deploymentRouter()->route('text-embedding-3-small'));
    }
}
