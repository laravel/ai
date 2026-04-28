<?php

namespace Laravel\Ai\Gateway\VoyageAi\Concerns;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\Video as VideoFile;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

trait MapsEmbeddingInputs
{
    /**
     * Determine if any embeddings input requires the multimodal endpoint.
     */
    protected function hasMultimodalEmbeddingInputs(array $inputs): bool
    {
        return (new Collection($inputs))->contains(fn ($input) => ! is_string($input));
    }

    /**
     * Generate embeddings for text and image inputs using Voyage AI's multimodal endpoint.
     */
    protected function generateMultimodalEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        $this->validateMultimodalEmbeddingInputSources($inputs);

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/multimodalembeddings', [
                'model' => $model,
                'inputs' => array_map(fn (mixed $input) => [
                    'content' => [$this->mapMultimodalEmbeddingInput($input)],
                ], $inputs),
                'output_dimension' => $dimensions,
            ]),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? $data['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Validate Voyage AI's per-request media source constraint.
     */
    protected function validateMultimodalEmbeddingInputSources(array $inputs): void
    {
        $source = null;

        foreach ($inputs as $input) {
            $inputSource = $this->multimodalEmbeddingInputSource($input);

            if (is_null($inputSource)) {
                continue;
            }

            $source ??= $inputSource;

            if ($source !== $inputSource) {
                throw new InvalidArgumentException(
                    'Voyage AI multimodal embeddings inputs must use either URL media or base64 media exclusively.'
                );
            }
        }
    }

    /**
     * Get the media source type used by a multimodal embeddings input.
     */
    protected function multimodalEmbeddingInputSource(mixed $input): ?string
    {
        return match (true) {
            $input instanceof RemoteImage,
            $input instanceof RemoteVideo => 'url',
            $input instanceof ImageFile && $input instanceof StorableFile && ! $input instanceof ProviderImage,
            $input instanceof VideoFile && $input instanceof StorableFile => 'base64',
            default => null,
        };
    }

    /**
     * Map a Laravel embeddings input to Voyage AI's multimodal input segment.
     */
    protected function mapMultimodalEmbeddingInput(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'text' => $input,
            ];
        }

        if ($input instanceof RemoteImage) {
            return [
                'type' => 'image_url',
                'image_url' => $input->url,
            ];
        }

        if ($input instanceof RemoteVideo) {
            return [
                'type' => 'video_url',
                'video_url' => $input->url,
            ];
        }

        if ($input instanceof ImageFile && $input instanceof StorableFile && ! $input instanceof ProviderImage) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64,".base64_encode($input->content()),
            ];
        }

        if ($input instanceof VideoFile && $input instanceof StorableFile) {
            $mime = $input->mimeType() ?? 'video/mp4';

            return [
                'type' => 'video_base64',
                'video_base64' => "data:{$mime};base64,".base64_encode($input->content()),
            ];
        }

        throw new InvalidArgumentException('Unsupported Voyage AI multimodal embeddings input type ['.get_debug_type($input).']');
    }
}
