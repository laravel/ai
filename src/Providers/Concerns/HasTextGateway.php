<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\TextGateway;

trait HasTextGateway
{
    protected TextGateway $textGateway;

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ?? $this->gateway;
    }

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self
    {
        $this->textGateway = $gateway;

        return $this;
    }
}
