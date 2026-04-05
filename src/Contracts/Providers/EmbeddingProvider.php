<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Responses\EmbeddingsResponse;

interface EmbeddingProvider
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  array<int, string|Audio|Document|Image|Video>  $inputs
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null, int $timeout = 30): EmbeddingsResponse;

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway;

    /**
     * Set the provider's embedding gateway.
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway): self;

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string;

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int;
}
