<?php

namespace Laravel\Ai\Gateway\AzureOpenAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Providers\Provider;

trait CreatesAzureOpenAiClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Azure OpenAI v1-compatible API.
     */
    /**
     * @param  array<string, string>  $requestHeaders
     */
    protected function client(Provider $provider, ?int $timeout = null, array $requestHeaders = []): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $base = rtrim($config['url'] ?? '', '/');

        return $this->createClient(
            "{$base}/openai/v1",
            ['api-key' => $provider->providerCredentials()['key']],
            array_merge($config['headers'] ?? [], $requestHeaders),
            $timeout ?? 60,
        );
    }
}
