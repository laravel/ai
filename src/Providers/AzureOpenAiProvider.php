<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\AzureOpenAi\AzureOpenAiGateway;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    protected ?AzureOpenAiGateway $azureGateway = null;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the shared Azure OpenAI gateway instance.
     */
    protected function azureGateway(): AzureOpenAiGateway
    {
        return $this->azureGateway ??= new AzureOpenAiGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= $this->azureGateway();
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->azureGateway();
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
            'url' => rtrim($this->config['url'] ?? '', '/'),
            'api_version' => $this->config['api_version'] ?? '2025-04-01-preview',
        ]);
    }
}
