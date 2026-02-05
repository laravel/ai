<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\BatchGateway;
use Laravel\Ai\Gateway\OpenAiBatchGateway;

trait HasBatchGateway
{
    /**
     * The batch gateway implementation.
     */
    protected ?BatchGateway $batchGateway = null;

    /**
     * Get the provider's batch gateway.
     */
    public function batchGateway(): BatchGateway
    {
        if ($this->batchGateway) {
            return $this->batchGateway;
        }

        return $this->batchGateway = new OpenAiBatchGateway;
    }

    /**
     * Set the provider's batch gateway.
     */
    public function useBatchGateway(BatchGateway $gateway): self
    {
        $this->batchGateway = $gateway;

        return $this;
    }
}
