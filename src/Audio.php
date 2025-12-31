<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\PendingResponses\PendingAudioGeneration;

class Audio
{
    /**
     * Generate audio from the given text.
     */
    public static function of(string $text): PendingAudioGeneration
    {
        return new PendingAudioGeneration($text);
    }

    /**
     * Fake audio generation.
     */
    public static function fake(Closure|array $responses = []): AiManager
    {
        return Ai::fakeAudio($responses);
    }

    /**
     * Assert that audio was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertAudioGenerated($callback);
    }

    /**
     * Assert that audio was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertAudioNotGenerated($callback);
    }

    /**
     * Assert that audio was not generated matching a given truth test.
     */
    public static function assertWasntGenerated(Closure $callback): void
    {
        Ai::assertAudioNotGenerated($callback);
    }

    /**
     * Assert that no audio was generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoAudioGenerated();
    }

    /**
     * Determine if audio generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::isAudioFaked();
    }
}
