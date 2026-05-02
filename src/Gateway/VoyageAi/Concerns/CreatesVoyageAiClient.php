<?php

namespace Laravel\Ai\Gateway\VoyageAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesVoyageAiClient
{
    /**
     * Get an HTTP client for the Voyage AI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 30)
            ->throw();
    }

    /**
     * Get the base URL for the Voyage AI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.voyageai.com/v1', '/');
    }
}
