<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

use Laravel\Ai\Contracts\Providers\Azure\DeploymentRouter;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Azure\MapDeploymentRouter;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings {
        embeddings as traitEmbeddings;
    }
    use Concerns\GeneratesText {
        prompt as traitPrompt;
        stream as traitStream;
    }
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * The deployment router instance.
     */
    protected ?DeploymentRouter $router = null;

    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        return $this->traitPrompt(
            $prompt->withModel($this->deploymentRouter()->route($prompt->model))
        );
    }

    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        return $this->traitStream(
            $prompt->withModel($this->deploymentRouter()->route($prompt->model))
        );
    }

    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  string[]  $input
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null): EmbeddingsResponse
    {
        return $this->traitEmbeddings(
            $inputs,
            $dimensions,
            $this->deploymentRouter()->route($model ?? $this->defaultEmbeddingsModel())
        );
    }

    /**
     * Get the deployment router instance.
     */
    public function deploymentRouter(): DeploymentRouter
    {
        return $this->router ??= new MapDeploymentRouter($this->config['deployments'] ?? []);
    }

    /**
     * Set the deployment router instance.
     */
    public function useDeploymentRouter(DeploymentRouter $router): self
    {
        $this->router = $router;

        return $this;
    }

    /**
     * Get the credentials for the AI provider.
     *
     * Azure OpenAI uses API key authentication via the `api-key` header.
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'],
        ];
    }

    /**
     * Get the name of the default (deployment name) text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-4o';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-4o-mini';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['deployment'] ?? 'gpt-4o';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['embedding_deployment'] ?? 'text-embedding-3-small';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1536;
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return array_filter([
            'url' => $this->buildAzureBaseUrl(),
            'api_version' => $this->config['api_version'] ?? '2024-10-21',
        ]);
    }

    /**
     * Build the Azure OpenAI base URL.
     */
    protected function buildAzureBaseUrl(): string
    {
        $url = rtrim($this->config['url'] ?? '', '/');

        return "{$url}/openai/v1";
    }
}
