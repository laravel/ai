<?php

namespace Laravel\Ai;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Providers\Provider;

class CircuitBreaker
{
    /**
     * Determine if the given provider is available.
     */
    public function isAvailable(Provider $provider): bool
    {
        if (! config('ai.circuit_breaker.enabled', false)) {
            return true;
        }

        if ($this->cache()->get($this->openKey($provider))) {
            return false;
        }

        return true;
    }

    /**
     * Record a success for the given provider.
     */
    public function recordSuccess(Provider $provider): void
    {
        if (! config('ai.circuit_breaker.enabled', false)) {
            return;
        }

        $this->cache()->forget($this->failureKey($provider));
        $this->cache()->forget($this->openKey($provider));
    }

    /**
     * Record a failure for the given provider.
     */
    public function recordFailure(Provider $provider): void
    {
        if (! config('ai.circuit_breaker.enabled', false)) {
            return;
        }

        $failures = $this->cache()->increment($this->failureKey($provider));

        if ($failures >= config('ai.circuit_breaker.threshold', 5)) {
            $this->cache()->put(
                $this->openKey($provider),
                true,
                now()->addSeconds(config('ai.circuit_breaker.cooldown', 60))
            );
        }
    }

    /**
     * Get the cache key for recording failures.
     */
    protected function failureKey(Provider $provider): string
    {
        return 'laravel-ai:circuit-breaker:failures:'.$provider->name();
    }

    /**
     * Get the cache key for the open circuit state.
     */
    protected function openKey(Provider $provider): string
    {
        return 'laravel-ai:circuit-breaker:open:'.$provider->name();
    }

    /**
     * Get the cache store.
     */
    protected function cache(): CacheRepository
    {
        return Cache::store(config('ai.circuit_breaker.store'));
    }
}
