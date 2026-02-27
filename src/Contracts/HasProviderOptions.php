<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Enums\Lab;

interface HasProviderOptions
{
    /**
     * Get the provider-specific options to be passed to Prism.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array;
}
