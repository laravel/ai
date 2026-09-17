<?php

namespace Laravel\Ai;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Gateway\FakeClassificationGateway;
use Laravel\Ai\PendingResponses\PendingClassification;

class Classification
{
    /**
     * Create a new pending classification for the given state.
     *
     * @param  string|array<string, mixed>  $state
     *
     * @throws InvalidArgumentException if the state is blank.
     */
    public static function of(string|array $state): PendingClassification
    {
        return new PendingClassification($state);
    }

    /**
     * Fake classification operations.
     */
    public static function fake(Closure|array $responses = []): FakeClassificationGateway
    {
        return Ai::fakeClassification($responses);
    }

    /**
     * Assert that a classification was performed matching a given truth test.
     */
    public static function assertClassified(Closure $callback): void
    {
        Ai::assertClassified($callback);
    }

    /**
     * Assert that a classification was not performed matching a given truth test.
     */
    public static function assertNotClassified(Closure $callback): void
    {
        Ai::assertNotClassified($callback);
    }

    /**
     * Assert that no classifications were performed.
     */
    public static function assertNothingClassified(): void
    {
        Ai::assertNothingClassified();
    }

    /**
     * Determine if classification is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::classificationIsFaked();
    }
}
