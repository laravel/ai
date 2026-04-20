<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Laravel\Ai\Providers\Provider;

trait CreatesBedrockClient
{
    /**
     * Resolve normalized Bedrock provider config for Prism.
     */
    protected function bedrockProviderConfig(Provider $provider): array
    {
        $credentials = $provider->providerCredentials();
        $configuration = $provider->additionalConfiguration();

        $hasStaticCredentials = filled($credentials['key'] ?? null)
            && filled($credentials['secret'] ?? null);

        return array_filter([
            ...$configuration,
            'api_key' => $credentials['key'] ?? null,
            'api_secret' => $credentials['secret'] ?? null,
            'session_token' => $credentials['session_token'] ?? null,
            'use_default_credential_provider' => $hasStaticCredentials
                ? false
                : ($configuration['use_default_credential_provider'] ?? true),
        ], fn (mixed $value) => ! is_null($value));
    }
}
