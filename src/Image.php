<?php

namespace Laravel\Ai;

use Closure;
use Laravel\Ai\PendingResponses\PendingImageGeneration;

class Image
{
    /**
     * Generate an image.
     */
    public static function of(string $prompt): PendingImageGeneration
    {
        return new PendingImageGeneration($prompt);
    }

    /**
     * Fake image generation.
     */
    public static function fake(Closure|array $responses = []): AiManager
    {
        return Ai::fakeImages($responses);
    }

    /**
     * Assert that an image was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertImageGenerated($callback);
    }

    /**
     * Assert that an image was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertImageNotGenerated($callback);
    }

    /**
     * Assert that no images were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoImagesGenerated();
    }

    /**
     * Determine if image generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::areImagesFaked();
    }
}
