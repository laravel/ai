<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Providers\Tools\WebFetch;

interface SupportsWebFetch
{
    /**
     * Get the web fetch tool options for the provider.
     */
    public function webFetchToolOptions(WebFetch $fetch): array;
}
