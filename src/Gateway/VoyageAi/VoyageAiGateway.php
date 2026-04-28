<?php

namespace Laravel\Ai\Gateway\VoyageAi;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
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
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        if ($this->hasMultimodalEmbeddingInputs($inputs)) {
            return $this->generateMultimodalEmbeddings($provider, $model, $inputs, $dimensions, $timeout);
        }

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/embeddings', [
                'model' => $model,
                'input' => $inputs,
                'output_dimension' => $dimensions,
            ]),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data']))->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Determine if any embeddings input requires the multimodal endpoint.
     */
    protected function hasMultimodalEmbeddingInputs(array $inputs): bool
    {
        return (new Collection($inputs))->contains(fn ($input) => ! is_string($input));
    }

    /**
     * Generate embeddings for text and image inputs using Voyage AI's multimodal endpoint.
     */
    protected function generateMultimodalEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
    ): EmbeddingsResponse {
        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/multimodalembeddings', [
                'model' => $model,
                'inputs' => array_map(fn (mixed $input) => [
                    'content' => [$this->mapMultimodalEmbeddingInput($input)],
                ], $inputs),
                'output_dimension' => $dimensions,
            ]),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data'] ?? []))->pluck('embedding')->all(),
            $data['usage']['total_tokens'] ?? $data['total_tokens'] ?? 0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map a Laravel embeddings input to Voyage AI's multimodal input segment.
     */
    protected function mapMultimodalEmbeddingInput(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'type' => 'text',
                'text' => $input,
            ];
        }

        if ($input instanceof RemoteImage) {
            return [
                'type' => 'image_url',
                'image_url' => $input->url,
            ];
        }

        if ($input instanceof ImageFile && $input instanceof StorableFile && ! $input instanceof ProviderImage) {
            $mime = $input->mimeType() ?? 'image/png';

            return [
                'type' => 'image_base64',
                'image_base64' => "data:{$mime};base64,".base64_encode($input->content()),
            ];
        }

        throw new InvalidArgumentException('Unsupported Voyage AI multimodal embeddings input type ['.get_debug_type($input).']');
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
