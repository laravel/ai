<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\ClassificationGateway;

trait HasClassificationGateway
{
    protected ClassificationGateway $classificationGateway;

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway;
    }

    /**
     * Set the provider's classification gateway.
     */
    public function useClassificationGateway(ClassificationGateway $gateway): self
    {
        $this->classificationGateway = $gateway;

        return $this;
    }
}
