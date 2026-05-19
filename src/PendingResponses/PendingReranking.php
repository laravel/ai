<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\RerankingResponse;

class PendingReranking
{
    use Conditionable;

    protected ?int $limit = null;

    /**
     * Create a new pending reranking instance.
     *
     * @param  array<int, string>  $documents
     *
     * @throws InvalidArgumentException if the documents are not a list, are empty, or contain non-string or blank entries.
     */
    public function __construct(
        protected array $documents,
    ) {
        if (! array_is_list($documents)) {
            throw new InvalidArgumentException('Documents to rerank must be a list, not an associative array.');
        }

        if (blank($documents)) {
            throw new InvalidArgumentException('At least one document is required to rerank.');
        }

        foreach ($documents as $index => $document) {
            if (! is_string($document) || blank($document)) {
                throw new InvalidArgumentException("Each document to rerank must be a non-blank string (index {$index}).");
            }
        }
    }

    /**
     * Limit the number of results to return.
     */
    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Rerank the documents based on their relevance to the query.
     *
     * @throws FailoverableException if every configured provider fails to rerank the documents.
     */
    public function rerank(string $query, Lab|array|string|null $provider = null, ?string $model = null): RerankingResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_reranking'), $model
        );

        $lastException = null;

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableRerankingProvider($provider);

            $model ??= $provider->defaultRerankingModel();

            try {
                return $provider->rerank($this->documents, $query, $this->limit, $model);
            } catch (FailoverableException $e) {
                $lastException = $e;

                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $lastException;
    }
}
