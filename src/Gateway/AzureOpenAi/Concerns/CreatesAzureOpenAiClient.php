<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    /**
     * Get an HTTP client for the Azure OpenAI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->baseUrl($this->baseUrl($provider))
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the Azure OpenAI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'], '/');
    }
}
