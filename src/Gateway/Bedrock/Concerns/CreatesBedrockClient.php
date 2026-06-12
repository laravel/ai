<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Laravel\Ai\Providers\Provider;

trait CreatesBedrockClient
{
    /**
     * Create a new Bedrock client instance.
     */
    protected function createBedrockClient(Provider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();

        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => '2023-09-30',
            ...$this->resolveAuthConfig($credentials, $config),
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        return new BedrockRuntimeClient($clientConfig);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveAuthConfig(array $credentials, array $config): array
    {
        if (! empty($credentials['key'])) {
            return [
                'token' => ['token' => $credentials['key']],
                'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
            ];
        }

        if (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            $awsCredentials = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $awsCredentials['token'] = $credentials['session_token'];
            }

            return ['credentials' => $awsCredentials];
        }

        if (! ($config['use_default_credential_provider'] ?? true)) {
            return ['credentials' => false];
        }

        return [];
    }
}
