<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible\Concerns;

use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesOpenAiCompatibleClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the OpenAI-compatible API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        return $this->createClient(
            $this->baseUrl($provider),
            filled($key) ? ['Authorization' => 'Bearer '.$key] : [],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? 60,
        );
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

        return rtrim((string) $url, '/');
    }
}
