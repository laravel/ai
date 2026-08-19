<?php

namespace Laravel\Ai\Contracts\Providers;

interface Provider
{
    /**
     * Get the name of the underlying AI provider.
     */
    public function name(): string;

    /**
     * Get the name of the underlying AI driver.
     */
    public function driver(): string;

    /**
     * Get the credentials for the underlying AI provider.
     *
     * @return array<string, mixed>
     */
    public function providerCredentials(): array;

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * @return array<string, mixed>
     */
    public function additionalConfiguration(): array;

    /**
     * Get a provider instance that sends the given HTTP headers with each request.
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static;
}
