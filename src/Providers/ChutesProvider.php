<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\ChutesAudioGateway;
use Laravel\Ai\Gateway\ChutesEmbeddingGateway;
use Laravel\Ai\Gateway\ChutesImageGateway;

class ChutesProvider extends Provider implements AudioProvider, EmbeddingProvider, ImageProvider, TextProvider, TranscriptionProvider
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

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return ['url' => 'https://llm.chutes.ai/v1'];
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'deepseek-ai/DeepSeek-V3';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'unsloth/gemma-3-4b-it';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'moonshotai/Kimi-K2.5';
    }

    /**
     * Get the image gateway instance.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ?? new ChutesImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return 'FLUX.1-schnell';
    }

    /**
     * Get the default image generation options.
     *
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        [$width, $height] = match ($size) {
            '1:1' => [1024, 1024],
            '3:2' => [1536, 1024],
            '2:3' => [1024, 1536],
            default => [1024, 1024],
        };

        return [
            'width' => $width,
            'height' => $height,
            'steps' => match ($quality) {
                'low' => 5,
                'medium' => 10,
                'high' => 20,
                default => 10,
            },
            'guidance_scale' => 7.5,
        ];
    }

    /**
     * Get the audio gateway instance.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ?? new ChutesAudioGateway;
    }

    /**
     * Get the transcription gateway instance.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ?? new ChutesAudioGateway;
    }

    /**
     * Get the name of the default audio model.
     */
    public function defaultAudioModel(): string
    {
        return 'kokoro';
    }

    /**
     * Get the name of the default transcription model.
     */
    public function defaultTranscriptionModel(): string
    {
        return 'whisper-large-v3';
    }

    /**
     * Get the embedding gateway instance.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ?? new ChutesEmbeddingGateway;
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'Qwen/Qwen3-Embedding-0.6B';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 1024;
    }
}
