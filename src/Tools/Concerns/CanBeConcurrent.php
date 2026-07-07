<?php

namespace Laravel\Ai\Tools\Concerns;

trait CanBeConcurrent
{
    protected bool $concurrent = false;

    /**
     * Mark the tool as safe to run alongside other concurrent tools.
     */
    public function concurrent(bool $concurrent = true): static
    {
        $this->concurrent = $concurrent;

        return $this;
    }

    /**
     * Determine whether the tool has been marked as concurrent.
     */
    public function isConcurrent(): bool
    {
        return $this->concurrent;
    }
}
