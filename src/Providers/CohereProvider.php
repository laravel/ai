<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Gateway\CohereRerankingGateway;

class CohereProvider extends Provider implements RerankingProvider
{
    use Concerns\HasRerankingGateway;
    use Concerns\Reranks;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    /**
     * Get the name of the default reranking model.
     */
    public function defaultRerankingModel(): string
    {
        return 'rerank-v3.5';
    }

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new CohereRerankingGateway;
    }
}
