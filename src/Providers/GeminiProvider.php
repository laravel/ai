<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\GeminiFileGateway;
use Laravel\Ai\Gateway\GeminiStoreGateway;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

class GeminiProvider extends Provider implements EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsWebFetch, SupportsWebSearch, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasFileGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasStoreGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\ManagesStores;
    use Concerns\StreamsText;

    /**
     * Get the web fetch tool options for the provider.
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return [];
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return [];
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'gemini-3-flash-preview';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'gemini-3-pro-image-preview';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return array_filter([
            'size' => match ($quality) {
                'low', '1K' => '1K',
                'medium', '2K' => '2K',
                'high', '4K' => '4K',
                default => '1K',
            },
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                default => null,
            },
        ]);
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'gemini-embedding-001';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 3072;
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new GeminiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new GeminiStoreGateway;
    }
}
