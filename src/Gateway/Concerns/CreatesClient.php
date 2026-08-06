<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait CreatesClient
{
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
            ->mapWithKeys(fn (mixed $value, string $name): array => [strtolower($name) => $value])
            ->all();

        return Http::baseUrl($baseUrl)
            ->withHeaders($headers)
            ->when($timeout !== null, fn (PendingRequest $client) => $client->timeout($timeout))
            ->when($throw, fn (PendingRequest $client) => $client->throw());
    }
}
