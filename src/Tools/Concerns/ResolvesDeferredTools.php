<?php

namespace Laravel\Ai\Tools\Concerns;

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;

trait ResolvesDeferredTools
{
    /**
     * Determine whether the tool opts into deferred loading via its provider options.
     */
    protected function isDeferred(Tool $tool, Lab $provider): bool
    {
        $options = $this->deferredOptions($tool, $provider);

        return (bool) ($options['defer_loading'] ?? false);
    }

    /**
     * Get the tool's provider options for deferral, if it carries any.
     *
     * @return array<string, mixed>
     */
    protected function deferredOptions(Tool $tool, Lab $provider): array
    {
        return $tool instanceof HasProviderOptions ? $tool->providerOptions($provider) : [];
    }
}
