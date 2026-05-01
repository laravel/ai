<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesXaiClient
{
    /**
     * Get an HTTP client for the xAI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the xAI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.x.ai/v1', '/');
    }
}
