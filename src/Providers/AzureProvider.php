<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

class AzureProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'],
            'url' => $this->config['url'],
            'api_version' => $this->config['api_version'],
        ];
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'gpt-4o';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'gpt-4o-mini';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'gpt-4o';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'text-embedding-3-small';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 1536;
    }
}
