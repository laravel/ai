<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\Gateway\FakeVideoGateway;
use Laravel\Ai\PendingResponses\PendingVideoGeneration;

class Video
{
    /**
     * Generate a video from a text prompt.
     */
    public static function of(string $prompt): PendingVideoGeneration
    {
        return new PendingVideoGeneration($prompt);
    }

    /**
     * Fake video generation.
     */
    public static function fake(Closure|array $responses = []): FakeVideoGateway
    {
        return Ai::fakeVideos($responses);
    }

    /**
     * Assert that a video was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertVideoGenerated($callback);
    }

    /**
     * Assert that a video was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertVideoNotGenerated($callback);
    }

    /**
     * Assert that no videos were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoVideosGenerated();
    }

    /**
     * Assert that a queued video generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertVideoQueued($callback);
    }

    /**
     * Assert that a queued video generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertVideoNotQueued($callback);
    }

    /**
     * Assert that no queued video generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoVideosQueued();
    }

    /**
     * Determine if video generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::videosAreFaked();
    }
}
