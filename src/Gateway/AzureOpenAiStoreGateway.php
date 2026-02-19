<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\StoreProvider;

class AzureOpenAiStoreGateway extends OpenAiStoreGateway
{
    /**
     * Get a configured HTTP client for the given provider.
     */
    protected function client(StoreProvider $provider): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->baseUrl($config['url']);
    }
}
