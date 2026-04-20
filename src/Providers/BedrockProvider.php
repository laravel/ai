<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

class BedrockProvider extends Provider implements EmbeddingProvider, ImageProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the credentials for the Bedrock provider.
     */
    public function providerCredentials(): array
    {
        return array_filter([
            'key' => $this->config['key'] ?? null,
            'secret' => $this->config['secret'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than credentials.
     */
    public function additionalConfiguration(): array
    {
        return array_filter([
            'region' => $this->config['region'] ?? 'us-east-1',
            'url' => $this->config['url'] ?? null,
            'use_default_credential_provider' => $this->config['use_default_credential_provider'] ?? true,
        ], fn (mixed $value) => ! is_null($value));
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'anthropic.claude-3-5-sonnet-20241022-v2:0';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'amazon.nova-lite-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'anthropic.claude-opus-4-1-20250805-v1:0';
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'amazon.nova-canvas-v1:0';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                default => null,
            },
            'quality' => match ($quality) {
                'high' => 'premium',
                'medium' => 'standard',
                'low' => 'draft',
                default => null,
            },
        ]);
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'amazon.titan-embed-text-v2:0';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }
}
