<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    /**
     * Get an HTTP client for the Azure OpenAI API.
     *
     * Supports two endpoint styles:
     *  - v1 (OpenAI-compatible): URL contains "/openai/v1" — no deployment path, no api-version
     *  - Classic deployment: URL is the resource root — appends /openai/deployments/{deployment} and api-version
     */
    protected function client(Provider $provider, string $deployment, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $url = $config['url'] ?? '';

        $request = Http::withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->timeout($timeout ?? 60)
            ->throw();

        if (str_contains($url, '/openai/v1')) {
            return $request->baseUrl($url);
        }

        return $request
            ->baseUrl("{$url}/openai/deployments/{$deployment}")
            ->withQueryParameters(['api-version' => $config['api_version'] ?? '2024-10-21']);
    }
}
