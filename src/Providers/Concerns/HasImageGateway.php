<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\ImageGateway;

trait HasImageGateway
{
    protected ImageGateway $imageGateway;

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ?? $this->gateway;
    }

    /**
     * Set the provider's image gateway.
     */
    public function useImageGateway(ImageGateway $gateway): self
    {
        $this->imageGateway = $gateway;

        return $this;
    }
}
