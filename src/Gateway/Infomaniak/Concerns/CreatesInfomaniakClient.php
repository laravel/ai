<?php

namespace Laravel\Ai\Gateway\Infomaniak\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\TextProvider;

trait CreatesInfomaniakClient
{
    protected function client(TextProvider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->config();

        return Http::baseUrl(rtrim($config['url'] ?? 'https://api.infomaniak.com/1/ai', '/'))
            ->withToken($config['key'] ?? '')
            ->timeout($timeout ?? $config['timeout'] ?? 60)
            ->throw();
    }
}
