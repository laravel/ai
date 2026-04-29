<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesOllamaClient
{
    /**
     * Get an HTTP client for the Ollama API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl($provider))
            ->timeout($timeout ?? 60)
            ->throw();

        $key = $provider->providerCredentials()['key'] ?? null;

        if (filled($key)) {
            $client = $client->withToken($key);
        }

        return $client;
    }

    /**
     * Get the base URL for the Ollama API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'http://localhost:11434', '/');
    }
}
