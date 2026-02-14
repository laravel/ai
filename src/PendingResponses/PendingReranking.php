<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
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
     */
    public function __construct(
        protected array $documents,
    ) {}

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
     */
    public function rerank(string $query, Lab|array|string|null $provider = null, ?string $model = null): RerankingResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_reranking'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableRerankingProvider($provider);

            $model ??= $provider->defaultRerankingModel();

            try {
                return $provider->rerank($this->documents, $query, $this->limit, $model);
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }
}
