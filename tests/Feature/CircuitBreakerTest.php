<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Exceptions\CircuitBreakerOpenException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Ai;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.circuit_breaker.enabled', true);
        Config::set('ai.circuit_breaker.threshold', 2);
        Config::set('ai.circuit_breaker.cooldown', 60);
        Config::set('ai.circuit_breaker.store', 'array');
    }

    public function test_circuit_breaker_trips_after_failures()
    {
        $provider = Ai::textProvider('openai');

        // First failure
        try {
            $provider->execute(fn () => throw new ProviderOverloadedException);
        } catch (ProviderOverloadedException) {}

        // Second failure - should trip the circuit
        try {
            $provider->execute(fn () => throw new ProviderOverloadedException);
        } catch (ProviderOverloadedException) {}

        // Third call - should throw CircuitBreakerOpenException
        $this->expectException(CircuitBreakerOpenException::class);
        $provider->execute(fn () => 'success');
    }

    public function test_circuit_breaker_resets_after_success()
    {
        $provider = Ai::textProvider('openai');

        // First failure
        try {
            $provider->execute(fn () => throw new ProviderOverloadedException);
        } catch (ProviderOverloadedException) {}

        // Success should reset failure count
        $provider->execute(fn () => 'success');

        // Second failure - should NOT trip because it was reset
        try {
            $provider->execute(fn () => throw new ProviderOverloadedException);
        } catch (ProviderOverloadedException) {}

        // This should still be allowed
        $response = $provider->execute(fn () => 'success');
        $this->assertEquals('success', $response);
    }
}
