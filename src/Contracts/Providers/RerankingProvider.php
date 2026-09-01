<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Responses\RerankingResponse;

interface RerankingProvider extends Provider
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $providerOptions
     */
    public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null, array $providerOptions = []): RerankingResponse;

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway;

    /**
     * Set the provider's reranking gateway.
     */
    public function useRerankingGateway(RerankingGateway $gateway): self;

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string;
}
