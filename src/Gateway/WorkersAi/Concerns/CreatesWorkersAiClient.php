<?php

namespace Laravel\Ai\Gateway\WorkersAi\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;

trait CreatesWorkersAiClient
{
    /**
     * Get an HTTP client for the Workers AI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();

        $additionalConfig = $provider->additionalConfiguration();

        if (! empty($additionalConfig['session_affinity'])) {
            $client->withHeaders(['x-session-affinity' => $additionalConfig['session_affinity']]);
        }

        return $client;
    }

    /**
     * Get the base URL for the Workers AI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        $config = $provider->additionalConfiguration();

        if (! empty($config['url'])) {
            return rtrim($config['url'], '/');
        }

        $accountId = $config['account_id'] ?? '';

        if (empty($accountId)) {
            throw new AiException(
                "Workers AI requires an 'account_id' or explicit 'url' in your provider configuration. "
                .'Set CLOUDFLARE_ACCOUNT_ID in your .env file or provide a WORKERSAI_URL.'
            );
        }

        if (! empty($config['gateway'])) {
            return "https://gateway.ai.cloudflare.com/v1/{$accountId}/{$config['gateway']}/workers-ai/v1";
        }

        return "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/v1";
    }

    /**
     * Validate model name compatibility with the configured endpoint.
     */
    protected function validateModelName(Provider $provider, string $model): void
    {
        $url = $this->baseUrl($provider);

        if (str_ends_with(rtrim($url, '/'), '/compat') && ! str_starts_with($model, 'workers-ai/')) {
            throw new AiException(
                "Workers AI model '{$model}' requires the 'workers-ai/' prefix when using the /compat endpoint. "
                ."Either prefix your model name (e.g., 'workers-ai/{$model}') or use the 'gateway' config option "
                ."instead of an explicit URL — this handles routing automatically with no model name changes."
            );
        }
    }
}
