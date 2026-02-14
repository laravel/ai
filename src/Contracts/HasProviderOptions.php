<?php

namespace Laravel\Ai\Contracts;

interface HasProviderOptions
{
    /**
     * Get the provider-specific options to be passed to Prism.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(): array;
}
