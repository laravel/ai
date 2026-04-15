<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    /**
     * Get an HTTP client scoped to a specific Azure OpenAI deployment.
     */
    protected function client(Provider $provider, string $deployment, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $base = $config['url'] ?? '';

        return Http::baseUrl("{$base}/openai/deployments/{$deployment}")
            ->withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->withQueryParameters(['api-version' => $config['api_version'] ?? '2024-10-21'])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
