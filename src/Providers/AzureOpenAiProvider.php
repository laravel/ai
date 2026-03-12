<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

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

        if (str_contains($url, '/openai/v1')) {
            return $url;
        }

        return "{$url}/openai/v1";
    }
}
