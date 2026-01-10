<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;
use RuntimeException;

class FakeRerankingGateway implements RerankingGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayRerankings = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

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
        $prompt = new RerankingPrompt($documents, $query, $limit, $provider, $model);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt
    ): RerankingResponse {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $model, $prompt
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt
    ): RerankingResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayRerankings) {
                throw new RuntimeException('Attempted reranking without a fake response.');
            }

            $response = $this->generateFakeRanking($prompt->documents, $prompt->limit);
        }

        if ($response instanceof RerankingResponse) {
            return $response;
        }

        if (is_array($response) && isset($response[0]) && $response[0] instanceof RankedDocument) {
            return new RerankingResponse(
                $response,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate a fake ranking for the given documents.
     *
     * @param  array<int, string>  $documents
     * @return array<int, RankedDocument>
     */
    protected function generateFakeRanking(array $documents, ?int $limit = null): array
    {
        $indices = Arr::shuffle(array_keys($documents));

        $indices = array_slice($indices, 0, $limit ?? count($documents));

        $results = [];

        foreach ($indices as $position => $index) {
            $results[] = new RankedDocument(
                index: $index,
                document: $documents[$index],
                score: 1.0 - ($position * (1.0 / count($indices))),
            );
        }

        return $results;
    }

    /**
     * Indicate that an exception should be thrown if any reranking is not faked.
     */
    public function preventStrayRerankings(bool $prevent = true): self
    {
        $this->preventStrayRerankings = $prevent;

        return $this;
    }
}
