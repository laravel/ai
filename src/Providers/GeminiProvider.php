<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\GeminiAudioGateway;
use Laravel\Ai\Gateway\GeminiFileGateway;
use Laravel\Ai\Gateway\GeminiStoreGateway;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

class GeminiProvider extends Provider implements AudioProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsFileSearch, SupportsWebFetch, SupportsWebSearch, TextProvider
{
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasAudioGateway;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasFileGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasStoreGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\ManagesStores;
    use Concerns\StreamsText;

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'fileSearchStoreNames' => $search->ids(),
            'metadataFilter' => ! empty($search->filters)
                ? $this->formatMetadataFilter($search->filters)
                : null,
        ]);
    }

    /**
     * Format the file search metadata filter for Gemini's filter expression syntax.
     *
     * @param  array<int, array{type: string, key: string, value: mixed}>  $filters
     */
    protected function formatMetadataFilter(array $filters): string
    {
        return collect($filters)->map(fn ($filter) => match ($filter['type']) {
            'eq' => is_numeric($filter['value'])
                ? "{$filter['key']}={$filter['value']}"
                : "{$filter['key']}=\"{$filter['value']}\"",
            'ne' => is_numeric($filter['value'])
                ? "{$filter['key']}!={$filter['value']}"
                : "{$filter['key']}!=\"{$filter['value']}\"",
            'in' => '('.collect($filter['value'])->map(fn ($v) => is_numeric($v) ? "{$filter['key']}={$v}" : "{$filter['key']}=\"{$v}\""
            )->implode(' OR ').')',
        })->implode(' AND ');
    }

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
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'gemini-2.5-flash-lite';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'gemini-3-pro-preview';
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

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new GeminiAudioGateway;
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return 'gemini-2.5-flash-preview-tts';
    }
}
