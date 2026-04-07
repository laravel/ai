<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

class AzureOpenAiStoreGateway extends OpenAi\OpenAiStoreGateway
{
    /**
     * Get a configured HTTP client for the given provider.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->baseUrl($provider->additionalConfiguration()['url'])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
