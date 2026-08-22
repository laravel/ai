<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RealtimeGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\AzureOpenAi\AzureOpenAiFileGateway;
use Laravel\Ai\Gateway\AzureOpenAi\AzureOpenAiGateway;
use Laravel\Ai\Gateway\AzureOpenAi\AzureOpenAiRealtimeGateway;
use Laravel\Ai\Gateway\AzureOpenAi\AzureOpenAiStoreGateway;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;

class AzureOpenAiProvider extends Provider implements EmbeddingProvider, FileProvider, ImageProvider, RealtimeProvider, StoreProvider, SupportsFileSearch, SupportsWebSearch, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesRealtimeSessions;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasFileGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasRealtimeGateway;
    use Concerns\HasStoreGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\ManagesStores;
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
    public function textGateway(): StepTextGateway
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
    #[\Override]
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
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= $this->azureGateway();
    }

    /**
     * Get the name of the default image deployment.
     */
    public function defaultImageModel(): string
    {
        return $this->config['image_deployment'] ?? 'gpt-image-1';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '1024x1536',
                '3:2' => '1536x1024',
                default => $size,
            },
            'quality' => $quality,
        ]);
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
     * Get the name of the default realtime deployment.
     */
    public function defaultRealtimeModel(): string
    {
        return $this->config['realtime_deployment'] ?? $this->config['deployment'] ?? 'gpt-4o-realtime';
    }

    /**
     * Get the name of the default realtime voice.
     */
    public function defaultRealtimeVoice(): string
    {
        return $this->config['models']['realtime']['voice'] ?? 'alloy';
    }

    /**
     * Get the default realtime gateway.
     */
    protected function defaultRealtimeGateway(): RealtimeGateway
    {
        return new AzureOpenAiRealtimeGateway;
    }

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (filled($search->filters)) {
            throw new InvalidArgumentException('Azure OpenAI does not support file search metadata filters.');
        }

        return array_filter([
            'vector_store_ids' => $search->ids(),
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::Azure);

        $filters = array_merge(
            filled($search->allowedDomains) ? ['allowed_domains' => $search->allowedDomains] : [],
            $options['filters'] ?? [],
        );

        unset($options['filters']);

        return array_filter([
            'filters' => filled($filters) ? $filters : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $options;
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    #[\Override]
    public function additionalConfiguration(): array
    {
        return [
            'url' => rtrim($this->config['url'] ?? '', '/'),
            'api_version' => $this->config['api_version'] ?? '2025-04-01-preview',
            'store' => $this->config['store'] ?? true,
            'headers' => $this->config['headers'] ?? [],
        ];
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
