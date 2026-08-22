<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\Gateway\FakeRealtimeGateway;

class Realtime
{
    /**
     * Fake realtime session creation.
     */
    public static function fake(Closure|array $responses = []): FakeRealtimeGateway
    {
        return Ai::fakeRealtime($responses);
    }

    /**
     * Assert that a realtime session was created matching a given truth test.
     */
    public static function assertSessionCreated(Closure $callback): void
    {
        Ai::assertRealtimeSessionCreated($callback);
    }

    /**
     * Assert that a realtime session was not created matching a given truth test.
     */
    public static function assertSessionNotCreated(Closure $callback): void
    {
        Ai::assertRealtimeSessionNotCreated($callback);
    }

    /**
     * Assert that no realtime sessions were created.
     */
    public static function assertNothingCreated(): void
    {
        Ai::assertNoRealtimeSessionCreated();
    }

    /**
     * Determine if realtime session creation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::realtimeIsFaked();
    }
}
