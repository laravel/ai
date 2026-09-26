<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\ClassificationGateway;
use Laravel\Ai\Gateway\VonGateway;

class VonProvider extends TypeSafeProvider
{
    /**
     * Get the name of the default classification model.
     */
    public function defaultClassificationModel(): string
    {
        return $this->config['models']['classification']['default'] ?? 'von-1.1.0';
    }

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway
    {
        return $this->classificationGateway ??= new VonGateway;
    }
}
