<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Laravel\Ai\Providers\Provider;

trait CreatesBedrockClient
{
    /**
     * Create a Bedrock Runtime client for the given provider.
     */
    protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();
        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => '2023-09-30',
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        if (! empty($credentials['bearer_token'])) {
            $clientConfig['credentials'] = [
                'token' => $credentials['bearer_token'],
            ];
        } elseif (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            $clientConfig['credentials'] = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $clientConfig['credentials']['token'] = $credentials['session_token'];
            }
        }

        return new BedrockRuntimeClient($clientConfig);
    }
}
