<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

class GeminiProvider extends Provider implements EmbeddingProvider, ImageProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'gemini-3-flash-preview';
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->gateway;
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
}
