<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Credentials\AssumeRoleCredentialProvider;
use Aws\Credentials\CredentialProvider;
use Aws\Sts\StsClient;
use Closure;
use Laravel\Ai\Providers\Provider;

trait CreatesBedrockClient
{
    /**
     * The memoized assume role credential providers, keyed by the resolved credential configuration.
     *
     * @var array<string, Closure>
     */
    protected array $assumeRoleProviders = [];

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
            ...$this->resolveAuthConfig($credentials, $config, $timeout),
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
    protected function resolveAuthConfig(array $credentials, array $config, ?int $timeout = null): array
    {
        if (! empty($credentials['key'])) {
            return [
                'token' => ['token' => $credentials['key']],
                'auth_scheme_preference' => ['smithy.api#httpBearerAuth'],
            ];
        }

        if (! empty($config['assume_role']['arn'])) {
            return ['credentials' => $this->assumeRoleCredentialProvider($credentials, $config, $timeout)];
        }

        return $this->resolveSourceAuthConfig($credentials, $config);
    }

    /**
     * Resolve the auth configuration for static, or automatically discovered, credentials.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveSourceAuthConfig(array $credentials, array $config): array
    {
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

    /**
     * Resolve a memoized credential provider that assumes the configured role.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config
     */
    protected function assumeRoleCredentialProvider(array $credentials, array $config, ?int $timeout = null): Closure
    {
        $key = hash('xxh128', serialize([$credentials, $config, $timeout]));

        return $this->assumeRoleProviders[$key] ??= CredentialProvider::memoize(
            new AssumeRoleCredentialProvider([
                'client' => $this->createStsClient($credentials, $config, $timeout),
                'assume_role_params' => $this->assumeRoleParameters($config['assume_role']),
            ])
        );
    }

    /**
     * Create the STS client used to assume the configured role.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config
     */
    protected function createStsClient(array $credentials, array $config, ?int $timeout = null): StsClient
    {
        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
            ...$this->resolveSourceAuthConfig($credentials, $config),
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        return new StsClient($clientConfig);
    }

    /**
     * Build the parameters for the STS assume role request.
     *
     * @param  array<string, mixed>  $assumeRole
     * @return array<string, mixed>
     */
    protected function assumeRoleParameters(array $assumeRole): array
    {
        return array_filter([
            'RoleArn' => $assumeRole['arn'],
            'RoleSessionName' => ($assumeRole['session_name'] ?? null) ?: 'laravel-ai-bedrock',
            'DurationSeconds' => (int) ($assumeRole['duration_seconds'] ?? 0),
            'ExternalId' => $assumeRole['external_id'] ?? null,
        ]);
    }
}
