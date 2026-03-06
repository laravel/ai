<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Gateway\VoyageAiGateway;

class VoyageAiProvider extends Provider implements EmbeddingProvider, RerankingProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasRerankingGateway;
    use Concerns\Reranks;

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'voyage-4';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'rerank-2.5-lite';
    }

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new VoyageAiGateway;
    }
}
