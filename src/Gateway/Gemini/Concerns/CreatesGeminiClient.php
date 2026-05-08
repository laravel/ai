<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesGeminiClient
{
    /**
     * Get an HTTP client for the Gemini API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withHeaders(['x-goog-api-key' => $provider->providerCredentials()['key']])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the Gemini API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta/', '/');
    }
}
