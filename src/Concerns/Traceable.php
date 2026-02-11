<?php

namespace Laravel\Ai\Concerns;

trait Traceable
{
    /**
     * Get the tracing driver to use for this agent.
     *
     * Return null to use the default configured driver.
     */
    public function tracingDriver(): ?string
    {
        return null;
    }
}
