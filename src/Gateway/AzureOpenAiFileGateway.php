<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\FileProvider;

class AzureOpenAiFileGateway extends OpenAiFileGateway
{
    /**
     * Get a configured HTTP client for the given provider.
     */
    protected function client(FileProvider $provider): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::withHeaders(['api-key' => $provider->providerCredentials()['key']])
            ->baseUrl($config['url']);
    }

    /**
     * Get the file upload purpose.
     */
    protected function filePurpose(): string
    {
        return 'assistants';
    }
}
