<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Gateway\Infomaniak\InfomaniakGateway;

class InfomaniakProvider extends Provider implements \Laravel\Ai\Contracts\Providers\AudioProvider, \Laravel\Ai\Contracts\Providers\EmbeddingProvider, \Laravel\Ai\Contracts\Providers\ImageProvider, \Laravel\Ai\Contracts\Providers\TextProvider, \Laravel\Ai\Contracts\Providers\TranscriptionProvider
{
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasAudioGateway;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasTextGateway;
    use Concerns\HasTranscriptionGateway;
    use Concerns\StreamsText;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new InfomaniakGateway($this->events);
    }

    public function embeddingGateway(): \Laravel\Ai\Contracts\Gateway\EmbeddingGateway
    {
        return $this->embeddingGateway ??= new \Laravel\Ai\Gateway\Infomaniak\InfomaniakEmbeddingGateway;
    }

    public function imageGateway(): \Laravel\Ai\Contracts\Gateway\ImageGateway
    {
        return $this->imageGateway ??= new \Laravel\Ai\Gateway\Infomaniak\InfomaniakImageGateway;
    }

    public function transcriptionGateway(): \Laravel\Ai\Contracts\Gateway\TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new \Laravel\Ai\Gateway\Infomaniak\InfomaniakTranscriptionGateway;
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'mixtral';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'mistral-7b';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'mixtral';
    }

    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'sd3';
    }

    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'whisper-1';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'text-embedding-3-small';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1536;
    }

    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'tts-1';
    }

    public function defaultImageOptions(?string $size = null, $quality = null): array
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
}
