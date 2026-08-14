<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesOpenRouterClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the OpenRouter API.
     */
    /**
     * @param  array<string, string>  $requestHeaders
     */
    protected function client(Provider $provider, ?int $timeout = null, array $requestHeaders = []): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return $this->createClient(
            $this->baseUrl($provider),
            array_filter([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'HTTP-Referer' => $config['http_referer'] ?? null,
                'X-OpenRouter-Title' => $config['x_title'] ?? null,
            ]),
            array_merge($config['headers'] ?? [], $requestHeaders),
            $timeout ?? 60,
        );
    }

    /**
     * Get the base URL for the OpenRouter API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://openrouter.ai/api/v1', '/');
    }
}
