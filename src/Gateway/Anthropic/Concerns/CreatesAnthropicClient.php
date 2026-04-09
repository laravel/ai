<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAnthropicClient
{
    /**
     * Get an HTTP client for the Anthropic API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $headers = [
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => $config['version'] ?? '2023-06-01',
            'anthropic-beta' => $config['anthropic_beta'] ?? 'web-fetch-2025-09-10',
        ];

        return Http::baseUrl($this->baseUrl($provider))
            ->withHeaders($headers)
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the Anthropic API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.anthropic.com/v1', '/');
    }
}
