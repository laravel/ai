<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Files\Audio as AudioFile;
use Laravel\Ai\Files\Document as DocumentFile;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\Video as VideoFile;

trait MapsEmbeddingInputs
{
    /**
     * Normalize model names accepted by the Gemini API.
     */
    protected function normalizeEmbeddingModel(string $model): string
    {
        return str_starts_with($model, 'models/') ? substr($model, 7) : $model;
    }

    /**
     * Determine if the model uses Gemini's Embedding 2 API shape.
     */
    protected function isGeminiEmbedding2Model(string $model): bool
    {
        return in_array($this->normalizeEmbeddingModel($model), ['gemini-embedding-2', 'gemini-embedding-2-preview'], true);
    }

    /**
     * Determine if any embeddings input requires Gemini's multimodal endpoint.
     */
    protected function hasMultimodalEmbeddingInputs(array $inputs): bool
    {
        return (new Collection($inputs))->contains(fn ($input) => ! is_string($input));
    }

    /**
     * Map a Laravel embeddings input to a Gemini content part.
     */
    protected function mapEmbeddingInput(EmbeddingProvider $provider, mixed $input): array
    {
        if (is_string($input)) {
            return ['text' => $input];
        }

        if ($input instanceof ProviderImage || $input instanceof ProviderDocument) {
            return ['fileData' => $this->resolveEmbeddingProviderFileData($provider, $input)];
        }

        if ($input instanceof RemoteAudio || $input instanceof RemoteDocument || $input instanceof RemoteImage || $input instanceof RemoteVideo) {
            return [
                'fileData' => array_filter([
                    'mimeType' => $input->declaredMimeType(),
                    'fileUri' => $input->url,
                ]),
            ];
        }

        if ($input instanceof StorableFile && ($input instanceof AudioFile || $input instanceof DocumentFile || $input instanceof ImageFile || $input instanceof VideoFile)) {
            return [
                'inlineData' => [
                    'mimeType' => $input->mimeType() ?? $this->defaultEmbeddingMimeType($input),
                    'data' => base64_encode($input->content()),
                ],
            ];
        }

        throw new InvalidArgumentException('Unsupported embeddings input type ['.get_debug_type($input).']');
    }

    /**
     * Resolve a provider file ID to the URI Gemini expects in embedding parts.
     */
    protected function resolveEmbeddingProviderFileData(EmbeddingProvider $provider, ProviderImage|ProviderDocument $input): array
    {
        if (! $provider instanceof FileProvider) {
            throw new InvalidArgumentException(
                'Provider ['.$provider->driver().'] does not support retrieving files for embeddings.'
            );
        }

        $file = $provider->getFile($input->id());

        return array_filter([
            'mimeType' => $file->mimeType(),
            'fileUri' => $file->uri() ?? throw new InvalidArgumentException(
                'Provider file ['.$input->id().'] is missing a URI required for Gemini embeddings.'
            ),
        ]);
    }

    /**
     * Get a fallback MIME type for inline embeddings media.
     */
    protected function defaultEmbeddingMimeType(AudioFile|DocumentFile|ImageFile|VideoFile $input): string
    {
        return match (true) {
            $input instanceof AudioFile => 'audio/mpeg',
            $input instanceof ImageFile => 'image/png',
            $input instanceof VideoFile => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}
