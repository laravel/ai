<?php

use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\Concerns\CreatesClient;

function httpClient(array $headers = [], array $configuredHeaders = []): PendingRequest
{
    return (new class
    {
        use CreatesClient;

        public function client(array $headers, array $configuredHeaders): PendingRequest
        {
            return $this->createClient('https://example.com', $headers, $configuredHeaders);
        }
    })->client($headers, $configuredHeaders);
}

test('configured headers override defaults case insensitively', function (): void {
    $headers = httpClient(
        ['Authorization' => 'Bearer provider-key', 'Content-Type' => 'application/json'],
        [
            'authorization' => 'Bearer proxy-token',
            'X-Session-Affinity' => 'discarded',
            'x-session-affinity' => 'abc-123',
        ],
    )->getOptions()['headers'];

    expect($headers)->toBe([
        'authorization' => 'Bearer proxy-token',
        'content-type' => 'application/json',
        'x-session-affinity' => 'abc-123',
    ]);
});
