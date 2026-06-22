<?php

namespace Laravel\Ai\Contracts\Providers;

interface SupportsToolSearch
{
    /**
     * Determine if the provider supports hosted tool search for the given model.
     */
    public function supportsToolSearch(string $model): bool;
}
