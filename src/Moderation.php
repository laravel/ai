<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\FakeModerationGateway;
use Laravel\Ai\PendingResponses\PendingModeration;
use Laravel\Ai\Responses\ModerationResponse;

class Moderation
{
    /**
     * Check the given input for content that may violate usage policies.
     */
    public static function check(string $input, Lab|array|string|null $provider = null, ?string $model = null): ModerationResponse
    {
        return (new PendingModeration($input))->check($provider, $model);
    }

    /**
     * Fake moderation operations.
     */
    public static function fake(Closure|array $responses = []): FakeModerationGateway
    {
        return Ai::fakeModeration($responses);
    }

    /**
     * Assert that a moderation was performed matching a given truth test.
     */
    public static function assertChecked(Closure $callback): void
    {
        Ai::assertChecked($callback);
    }

    /**
     * Assert that a moderation was not performed matching a given truth test.
     */
    public static function assertNotChecked(Closure $callback): void
    {
        Ai::assertNotChecked($callback);
    }

    /**
     * Assert that no moderations were performed.
     */
    public static function assertNothingChecked(): void
    {
        Ai::assertNothingChecked();
    }

    /**
     * Determine if moderation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::moderationIsFaked();
    }
}
