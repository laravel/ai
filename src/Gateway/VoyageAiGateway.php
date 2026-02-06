<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;

class VoyageAiGateway implements RerankingGateway
{
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
        $response = $this->client($provider)->post('/rerank', array_filter([
            'model' => $model,
            'query' => $query,
            'documents' => $documents,
            'top_k' => $limit,
        ]));

        $data = $response->json();

        $results = collect($data['data'])->map(fn (array $result) => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->all();

        return new RerankingResponse(
            $results,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Cohere API.
     */
    protected function client(EmbeddingProvider|RerankingProvider $provider): PendingRequest
    {
        return Http::baseUrl('https://api.voyageai.com/v1')
            ->withHeaders([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ])
            ->throw();
    }
}
