<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;

trait HasEmbeddingGateway
{
    protected EmbeddingGateway $embeddingGateway;

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ?? $this->gateway;
    }

    /**
     * Set the provider's embedding gateway.
     */
    public function useEmbeddingGateway(EmbeddingGateway $gateway): self
    {
        $this->embeddingGateway = $gateway;

        return $this;
    }
}
