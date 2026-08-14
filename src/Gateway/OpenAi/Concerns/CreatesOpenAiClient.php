<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesOpenAiClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the OpenAI API.
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
     * Get the base URL for the OpenAI-compatible API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.openai.com/v1', '/');
    }
}
