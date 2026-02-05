<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\Gateway\FakeModerationGateway;
use Laravel\Ai\PendingResponses\PendingModerationGeneration;

class Moderation
{
    /**
     * Moderate the given input(s).
     *
     * @param  string|string[]  $input
     */
    public static function of(string|array $input): PendingModerationGeneration
    {
        return new PendingModerationGeneration($input);
    }

    /**
     * Fake moderation generation.
     */
    public static function fake(Closure|array $responses = []): FakeModerationGateway
    {
        return Ai::fakeModerations($responses);
    }

    /**
     * Assert that a moderation was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertModerationGenerated($callback);
    }

    /**
     * Assert that a moderation was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertModerationNotGenerated($callback);
    }

    /**
     * Assert that no moderations were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoModerationsGenerated();
    }

    /**
     * Determine if moderation generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::moderationsAreFaked();
    }
}
