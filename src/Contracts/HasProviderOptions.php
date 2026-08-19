<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Enums\Lab;

interface HasProviderOptions
{
    /**
     * The provider option key that carries HTTP headers rather than request body parameters.
     */
    public const HEADERS = 'extra_headers';

    /**
     * Get the provider-specific options to be passed to the provider.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array;
}
