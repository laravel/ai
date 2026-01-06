<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Gateway\StoreGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\OpenAiFileGateway;
use Laravel\Ai\Gateway\OpenAiStoreGateway;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebSearch;

class OpenAiProvider extends Provider implements AudioProvider, EmbeddingProvider, FileProvider, ImageProvider, StoreProvider, SupportsFileSearch, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasAudioGateway;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasFileGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasStoreGateway;
    use Concerns\HasTextGateway;
    use Concerns\HasTranscriptionGateway;
    use Concerns\ManagesFiles;
    use Concerns\ManagesStores;
    use Concerns\StreamsText;

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        return array_filter([
            'vector_store_ids' => $search->ids(),
            'filters' => ! empty($search->where) ? [
                'type' => 'and',
                'filters' => collect($search->where)->map(function ($value, $key) {
                    return [
                        'type' => 'eq',
                        'key' => $key,
                        'value' => $value,
                    ];
                })->values()->all(),
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
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'gpt-5.2';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'gpt-image-1.5';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return [
            'quality' => $quality ?? 'auto',
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '1024x1536',
                '3:2' => '1536x1024',
                null => 'auto',
                default => $size,
            },
            'moderation' => 'low',
        ];
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return 'gpt-4o-mini-tts';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return 'gpt-4o-transcribe-diarize';
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

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new OpenAiFileGateway;
    }

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway
    {
        return $this->storeGateway ??= new OpenAiStoreGateway;
    }
}
