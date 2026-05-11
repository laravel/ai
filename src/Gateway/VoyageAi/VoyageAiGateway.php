<?php

namespace Laravel\Ai\Gateway\VoyageAi;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;

class VoyageAiGateway implements EmbeddingGateway, RerankingGateway
{
    use Concerns\CreatesVoyageAiClient;
    use HandlesFailoverErrors;

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'output_dimension' => $dimensions,
            ])),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data']))->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param  array<int, string>  $documents
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null
    ): RerankingResponse {
        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('/rerank', array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_k' => $limit,
            ])),
        )->json();

        return new RerankingResponse(
            collect($data['data'])->map(fn (array $result) => new RankedDocument(
                index: $result['index'],
                document: $documents[$result['index']],
                score: $result['relevance_score'],
            ))->all(),
            new Meta($provider->name(), $model),
        );
    }
}
