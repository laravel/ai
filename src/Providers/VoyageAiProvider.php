<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;

class VoyageAiProvider extends Provider implements EmbeddingProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\HasEmbeddingGateway;

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'voyage-3';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 1024;
    }
}
