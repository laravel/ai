<?php

namespace Laravel\Ai\Gateway\Nvidia\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesNvidiaClient
{
    /**
     * Get an HTTP client for the NVIDIA NIM API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the NVIDIA NIM API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://integrate.api.nvidia.com/v1', '/');
    }
}
