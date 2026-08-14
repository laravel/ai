<?php

namespace Laravel\Ai\Gateway\Groq\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesGroqClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Groq API.
     */
    /**
     * @param  array<string, string>  $requestHeaders
     */
    protected function client(Provider $provider, ?int $timeout = null, array $requestHeaders = []): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            ['Authorization' => 'Bearer '.$provider->providerCredentials()['key']],
            array_merge($provider->additionalConfiguration()['headers'] ?? [], $requestHeaders),
            $timeout ?? 60,
        );
    }

    /**
     * Get the base URL for the Groq API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.groq.com/openai/v1', '/');
    }
}
