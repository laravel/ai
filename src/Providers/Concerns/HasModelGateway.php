<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\ModelGateway;

trait HasModelGateway
{
    protected ModelGateway $modelGateway;

    /**
     * Get the provider's model gateway.
     */
    public function modelGateway(): ModelGateway
    {
        return $this->modelGateway ?? $this->gateway;
    }

    /**
     * Set the provider's model gateway.
     */
    public function useModelGateway(ModelGateway $gateway): self
    {
        $this->modelGateway = $gateway;

        return $this;
    }
}
