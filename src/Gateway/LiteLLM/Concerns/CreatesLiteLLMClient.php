<?php

namespace Laravel\Ai\Gateway\LiteLLM\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesLiteLLMClient
{
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }

    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'http://localhost:4000', '/');
    }
}
