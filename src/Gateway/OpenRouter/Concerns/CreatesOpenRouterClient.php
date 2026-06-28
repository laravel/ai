<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesOpenRouterClient
{
    /**
     * Get an HTTP client for the OpenRouter API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->withHeaders(array_filter([
                'HTTP-Referer' => $config['http_referer'] ?? null,
                'X-OpenRouter-Title' => $config['x_title'] ?? null,
            ]))
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the OpenRouter API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://openrouter.ai/api/v1', '/');
    }
}
