<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesMistralClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Mistral API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            ['Authorization' => 'Bearer '.$provider->providerCredentials()['key']],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? 60,
        );
    }

    /**
     * Get the base URL for the Mistral API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.mistral.ai/v1', '/');
    }
}
