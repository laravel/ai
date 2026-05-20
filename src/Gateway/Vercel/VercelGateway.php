<?php

namespace Laravel\Ai\Gateway\Vercel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Providers\Provider;

class VercelGateway extends OpenRouterGateway
{
    /**
     * Get an HTTP client for the Vercel AI Gateway API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Get the base URL for the Vercel AI Gateway API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://ai-gateway.vercel.sh/v1', '/');
    }
}
