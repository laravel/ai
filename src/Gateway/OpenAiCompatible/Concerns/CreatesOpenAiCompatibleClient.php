<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Providers\Provider;

trait CreatesOpenAiCompatibleClient
{
    /**
     * Get an HTTP client for the OpenAI-compatible API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl($provider))
            ->timeout($timeout ?? 60)
            ->throw();

        if (filled($key = $provider->providerCredentials()['key'] ?? null)) {
            $client->withToken($key);
        }

        return $client;
    }

    /**
     * Get the base URL for the OpenAI-compatible API.
     */
    protected function baseUrl(Provider $provider): string
    {
        $url = $provider->additionalConfiguration()['url'] ?? null;

        if (blank($url)) {
            throw new InvalidArgumentException(
                "The [{$provider->name()}] openai-compatible provider requires a 'url' to be configured."
            );
        }

        return rtrim($url, '/');
    }
}
