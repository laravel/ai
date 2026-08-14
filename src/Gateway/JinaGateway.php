<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
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

class JinaGateway implements EmbeddingGateway, RerankingGateway
{
    use Concerns\CreatesClient;
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
        array $headers = [],
    ): EmbeddingsResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout, $headers)->post('/embeddings', array_merge(
                ['task' => 'retrieval.passage'],
                $providerOptions,
                [
                    'model' => $model,
                    'input' => array_map(fn (string $text): array => ['text' => $text], $inputs),
                    'dimensions' => $dimensions,
                ],
            )),
        );

        $data = $response->json();

        $embeddings = (new Collection($data['data']))->pluck('embedding')->all();

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
        ?int $limit = null,
        array $headers = [],
    ): RerankingResponse {
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, requestHeaders: $headers)->post('/rerank', array_filter([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ])),
        );

        $data = $response->json();

        $results = (new Collection($data['results']))->map(fn (array $result): RankedDocument => new RankedDocument(
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
    /**
     * @param  array<string, string>  $requestHeaders
     */
    protected function client(EmbeddingProvider|RerankingProvider $provider, int $timeout = 30, array $requestHeaders = []): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            [
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            array_merge($provider->additionalConfiguration()['headers'] ?? [], $requestHeaders),
            $timeout,
        );
    }

    /**
     * Get the base URL for the Jina API.
     */
    protected function baseUrl(EmbeddingProvider|RerankingProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.jina.ai/v1', '/');
    }
}
