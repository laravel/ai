<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesAnthropicClient
{
    use CreatesClient;

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    /**
     * Get the authentication headers for the Anthropic API.
     *
     * @return array<string, string>
     */
    protected function clientHeaders(Provider $provider): array
    {
        $config = $provider->additionalConfiguration();

        return array_filter([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => $config['version'] ?? '2023-06-01',
            'anthropic-beta' => $config['anthropic_beta'] ?? 'web-fetch-2025-09-10',
        ]);
    }
}
