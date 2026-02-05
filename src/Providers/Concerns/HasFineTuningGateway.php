<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\FineTuningGateway;

trait HasFineTuningGateway
{
    /**
     * The fine-tuning gateway implementation.
     */
    protected ?FineTuningGateway $fineTuningGateway = null;

    /**
     * Get the provider's fine-tuning gateway.
     */
    public function fineTuningGateway(): FineTuningGateway
    {
        return $this->fineTuningGateway ??= $this->defaultFineTuningGateway();
    }

    /**
     * Set the provider's fine-tuning gateway.
     */
    public function useFineTuningGateway(FineTuningGateway $gateway): self
    {
        $this->fineTuningGateway = $gateway;

        return $this;
    }

    /**
     * Get the default fine-tuning gateway for the provider.
     */
    abstract protected function defaultFineTuningGateway(): FineTuningGateway;
}
