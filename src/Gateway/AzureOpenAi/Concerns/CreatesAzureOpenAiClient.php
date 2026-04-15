<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    /**
     * Get an HTTP client for the Azure OpenAI Responses API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $base = rtrim($config['url'] ?? '', '/');

        return Http::baseUrl("{$base}/openai")
            ->withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->withQueryParameters(['api-version' => $config['api_version'] ?? '2025-04-01-preview'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get an HTTP client scoped to a specific Azure OpenAI deployment (for embeddings).
     */
    protected function embeddingsClient(Provider $provider, string $deployment, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $base = rtrim($config['url'] ?? '', '/');

        return Http::baseUrl("{$base}/openai/deployments/{$deployment}")
            ->withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->withQueryParameters(['api-version' => $config['api_version'] ?? '2025-04-01-preview'])
            ->timeout($timeout ?? 30)
            ->throw();
    }
}
