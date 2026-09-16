<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesAnthropicClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Anthropic API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $headers = array_filter([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => $config['version'] ?? '2023-06-01',
            'anthropic-beta' => $config['anthropic_beta'] ?? 'web-fetch-2025-09-10',
        ]);

        return $this->createClient(
            $this->baseUrl($provider),
            $headers,
            $config['headers'] ?? [],
            $timeout ?? 60,
        );
    }

    /**
     * Get the base URL for the Anthropic API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.anthropic.com/v1', '/');
    }
}
