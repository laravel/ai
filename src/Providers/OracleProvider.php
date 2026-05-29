<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Oracle\OracleTextGateway;

class OracleProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function __construct(
        protected array $config,
        protected Dispatcher $events
    ) {}

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return array_filter([
            'tenancy_id' => $this->config['tenancy_id'] ?? null,
            'user_id' => $this->config['user_id'] ?? null,
            'fingerprint' => $this->config['fingerprint'] ?? null,
            'private_key' => $this->config['private_key'] ?? null,
            'private_key_path' => $this->config['private_key_path'] ?? null,
            'passphrase' => $this->config['passphrase'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return array_filter([
            'region' => $this->config['region'] ?? 'us-chicago-1',
            'compartment_id' => $this->config['compartment_id'] ?? null,
            'serving_type' => $this->config['serving_type'] ?? 'ON_DEMAND',
            'endpoint_id' => $this->config['endpoint_id'] ?? null,
            'url' => $this->config['url'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'cohere.command-a-03-2025';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'cohere.command-r-08-2024';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'cohere.command-a-03-2025';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'cohere.embed-multilingual-v3.0';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new OracleTextGateway;
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new OracleTextGateway;
    }
}
