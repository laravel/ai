<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesGeminiClient
{
    use CreatesClient;

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta/';
    }

    /**
     * Get the authentication headers for the Gemini API.
     *
     * @return array<string, string>
     */
    protected function clientHeaders(Provider $provider): array
    {
        return array_filter(['x-goog-api-key' => $provider->providerCredentials()['key']]);
    }
}
