<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\AudioGateway;

trait HasAudioGateway
{
    protected AudioGateway $audioGateway;

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ?? $this->gateway;
    }

    /**
     * Set the provider's audio gateway.
     */
    public function useAudioGateway(AudioGateway $gateway): self
    {
        $this->audioGateway = $gateway;

        return $this;
    }
}
