<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    use CreatesClient;

    /**
     * Get the base URL for the Azure OpenAI v1-compatible API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? '', '/').'/openai/v1';
    }

    /**
     * Get the authentication headers for the Azure OpenAI API.
     *
     * @return array<string, string>
     */
    protected function clientHeaders(Provider $provider): array
    {
        return ['api-key' => $provider->providerCredentials()['key']];
    }
}
