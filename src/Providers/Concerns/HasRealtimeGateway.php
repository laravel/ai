<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\RealtimeGateway;

trait HasRealtimeGateway
{
    protected RealtimeGateway $realtimeGateway;

    /**
     * Get the provider's realtime gateway.
     */
    public function realtimeGateway(): RealtimeGateway
    {
        return $this->realtimeGateway ?? $this->defaultRealtimeGateway();
    }

    /**
     * Set the provider's realtime gateway.
     */
    public function useRealtimeGateway(RealtimeGateway $gateway): self
    {
        $this->realtimeGateway = $gateway;

        return $this;
    }
}
