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

class JinaGateway implements EmbeddingGateway, RerankingGateway
{
    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions
    ): EmbeddingsResponse {
        $response = $this->client($provider)->post('/embeddings', [
            'model' => $model,
            'input' => array_map(fn (string $text) => ['text' => $text], $inputs),
            'dimensions' => $dimensions,
            'task' => 'retrieval.passage',
        ]);

        $data = $response->json();

        $embeddings = collect($data['data'])->pluck('embedding')->all();

        return new EmbeddingsResponse(
            $embeddings,
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
        $response = $this->client($provider)->post('/rerank', array_filter([
            'model' => $model,
            'query' => $query,
            'documents' => $documents,
            'top_n' => $limit,
        ]));

        $data = $response->json();

        $results = collect($data['results'])->map(fn (array $result) => new RankedDocument(
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
     * Get an HTTP client for the Jina API.
     */
    protected function client(EmbeddingProvider|RerankingProvider $provider): PendingRequest
    {
        return Http::baseUrl('https://api.jina.ai/v1')
            ->withHeaders([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ])
            ->throw();
    }
}
