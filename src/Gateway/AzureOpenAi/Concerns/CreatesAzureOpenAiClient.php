<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    /**
     * Get an HTTP client for the Azure OpenAI v1-compatible API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $base = rtrim($config['url'] ?? '', '/');

        return Http::baseUrl("{$base}/openai/v1")
            ->withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get an HTTP client scoped to a deployment-specific Azure path.
     *
     * Some Azure endpoints (image edits, fine-tuning, etc.) are only available
     * at `/openai/deployments/{deployment}/...?api-version=…`, not on the
     * v1-compatible base used by `client()`.
     */
    protected function deploymentClient(Provider $provider, string $deployment, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $base = rtrim($config['url'] ?? '', '/');
        $apiVersion = $config['api_version'] ?? '2025-04-01-preview';

        return Http::baseUrl("{$base}/openai/deployments/{$deployment}")
            ->withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->withQueryParameters(['api-version' => $apiVersion])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
