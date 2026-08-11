<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesClient
{
    /**
     * Get an HTTP client for the provider's API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            $this->clientHeaders($provider),
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? $this->defaultTimeout(),
            $this->throwsClientErrors(),
        );
    }

    /**
     * Get the base URL for the provider's API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? $this->defaultBaseUrl(), '/');
    }

    /**
     * Get the authentication headers for the provider's API.
     *
     * @return array<string, string>
     */
    protected function clientHeaders(Provider $provider): array
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        return filled($key) ? ['Authorization' => 'Bearer '.$key] : [];
    }

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return '';
    }

    /**
     * Get the request timeout in seconds, or null for no timeout.
     */
    protected function defaultTimeout(): ?int
    {
        return 60;
    }

    /**
     * Determine if client errors should be thrown as exceptions.
     */
    protected function throwsClientErrors(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $configuredHeaders
     */
    protected function createClient(
        string $baseUrl,
        array $headers = [],
        array $configuredHeaders = [],
        ?int $timeout = 60,
        bool $throw = true,
    ): PendingRequest {
        $headers = collect($headers)
            ->merge($configuredHeaders)
            ->groupBy(fn (mixed $value, string $name): string => strtolower($name), preserveKeys: true)
            ->mapWithKeys(fn (Collection $group): array => [$group->keys()->first() => $group->last()])
            ->all();

        return Http::baseUrl($baseUrl)
            ->withHeaders($headers)
            ->when($timeout !== null, fn (PendingRequest $client) => $client->timeout($timeout))
            ->when($throw, fn (PendingRequest $client) => $client->throw());
    }
}
