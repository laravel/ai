<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Responses\EmbeddingsResponse;

interface EmbeddingProvider
{
    /**
     * Get embedding vectors representing the given inputs.
     *
     * @param  string[]  $input
     */
    public function embeddings(array $inputs, ?int $dimensions = null, ?string $model = null): EmbeddingsResponse;

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
