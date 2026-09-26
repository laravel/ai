<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;

class VonGateway extends TypeSafeGateway
{
    /**
     * Get an HTTP client for the Von API.
     */
    protected function client(ClassificationProvider $provider, int $timeout = 30): PendingRequest
    {
        return $this->createClient(
            rtrim($provider->additionalConfiguration()['url'] ?? 'http://localhost:8000/v1', '/'),
            [
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
        );
    }
}
