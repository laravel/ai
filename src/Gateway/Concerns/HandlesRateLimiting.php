<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\RateLimitedException;

trait HandlesRateLimiting
{
    /**
     * Execute a callback and handle rate limiting exceptions.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withRateLimitHandling(string $providerName, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                throw RateLimitedException::forProvider(
                    $providerName, $e->getCode(), $e
                );
            }

            throw $e;
        }
    }
}
