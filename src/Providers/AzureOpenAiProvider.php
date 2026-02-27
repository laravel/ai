<?php

namespace Laravel\Ai\Providers;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\AzureOpenAiFileGateway;
use Laravel\Ai\Gateway\AzureOpenAiStoreGateway;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, FileProvider, StoreProvider, SupportsFileSearch, SupportsWebSearch, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasFileGateway;
    use Concerns\HasStoreGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\ManagesStores;
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

        return "{$url}/openai/v1";
    }

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'vector_store_ids' => $search->ids(),
            'filters' => ! empty($search->filters) ? [
                'type' => 'and',
                'filters' => (new Collection($search->filters))->map(fn ($filter) => match ($filter['type']) {
                    default => [
                        'type' => $filter['type'],
                        'key' => $filter['key'],
                        'value' => $filter['value'],
                    ],
                })->all(),
            ] : null,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'filters' => ! empty($search->allowedDomains)
                ? ['allowed_domains' => $search->allowedDomains]
                : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]);
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AzureOpenAiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new AzureOpenAiStoreGateway;
    }
}
