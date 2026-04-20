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
}
