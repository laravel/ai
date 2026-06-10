<?php

namespace Laravel\Ai\Contracts\Providers;

interface SupportsToolSearch
{
    /**
     * Determine whether the given model supports hosted tool search / deferred tool loading.
     */
    public function supportsToolSearch(string $model): bool;
}
