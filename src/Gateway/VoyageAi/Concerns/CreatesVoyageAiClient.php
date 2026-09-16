<?php

namespace Laravel\Ai\Gateway\VoyageAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesVoyageAiClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Voyage AI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            ['Authorization' => 'Bearer '.$provider->providerCredentials()['key']],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? 30,
        );
    }

    /**
     * Get the base URL for the Voyage AI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.voyageai.com/v1', '/');
    }
}
