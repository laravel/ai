<?php

namespace Laravel\Ai\Gateway\Oracle\Concerns;

use GuzzleHttp\Middleware;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Gateway\Oracle\OracleRequestSigner;
use Laravel\Ai\Providers\Provider;
use Psr\Http\Message\RequestInterface;

trait CreatesOracleClient
{
    /**
     * Create a new HTTP client for the OCI Generative AI inference API.
     *
     * Requests are signed per the OCI HTTP Signature scheme via a Guzzle request
     * middleware so the signature is computed over the final serialized body.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $signer = $this->oracleSigner($provider);

        return Http::baseUrl($this->baseUrl($provider))
            ->withHeaders(['Accept' => 'application/json'])
            ->withMiddleware(Middleware::mapRequest(
                fn (RequestInterface $request) => $signer->sign($request)
            ))
            ->timeout($timeout ?? 60)
            ->throw();
    }

    /**
     * Build the request signer from the provider's resolved credentials.
     */
    protected function oracleSigner(Provider $provider): OracleRequestSigner
    {
        $credentials = $provider->providerCredentials();

        foreach (['tenancy_id', 'user_id', 'fingerprint'] as $required) {
            if (empty($credentials[$required])) {
                throw new InvalidArgumentException("The OCI [{$required}] credential is required to sign Generative AI requests.");
            }
        }

        return new OracleRequestSigner(
            $credentials['tenancy_id'],
            $credentials['user_id'],
            $credentials['fingerprint'],
            $this->resolvePrivateKey($credentials),
            $credentials['passphrase'] ?? null,
        );
    }

    /**
     * Resolve the PEM private key from its inline value or filesystem path.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function resolvePrivateKey(array $credentials): string
    {
        if (! empty($credentials['private_key'])) {
            return $credentials['private_key'];
        }

        if (! empty($credentials['private_key_path']) && is_file($credentials['private_key_path'])) {
            return (string) file_get_contents($credentials['private_key_path']);
        }

        throw new InvalidArgumentException('An OCI private key (contents or path) is required to sign Generative AI requests.');
    }

    /**
     * Get the base URL for the OCI Generative AI inference API.
     */
    protected function baseUrl(Provider $provider): string
    {
        $config = $provider->additionalConfiguration();

        $url = $config['url']
            ?? 'https://inference.generativeai.'.($config['region'] ?? 'us-chicago-1').'.oci.oraclecloud.com';

        return rtrim($url, '/');
    }
}
