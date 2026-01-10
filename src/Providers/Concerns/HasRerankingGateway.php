<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\RerankingGateway;

trait HasRerankingGateway
{
    protected RerankingGateway $rerankingGateway;

    /**
     * Get the provider's reranking gateway.
     */
    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway;
    }

    /**
     * Set the provider's reranking gateway.
     */
    public function useRerankingGateway(RerankingGateway $gateway): self
    {
        $this->rerankingGateway = $gateway;

        return $this;
    }
}
