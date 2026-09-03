<?php

namespace Laravel\Ai;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Gateway\FakeAudioGateway;
use Laravel\Ai\Gateway\FakeVoiceGateway;
use Laravel\Ai\PendingResponses\PendingAudioGeneration;
use Laravel\Ai\Responses\VoicesResponse;

class Audio
{
    /**
     * Generate audio from the given text.
     *
     * @throws InvalidArgumentException if the given text is empty or whitespace-only.
     */
    public static function of(string $text): PendingAudioGeneration
    {
        return new PendingAudioGeneration($text);
    }

    /**
     * List the voices available for audio generation.
     */
    public static function voices(?string $provider = null, int $timeout = 30): VoicesResponse
    {
        return Ai::fakeableVoiceProvider(
            $provider ?? config('ai.default_for_audio')
        )->voices($timeout);
    }

    /**
     * Fake voice listing.
     */
    public static function fakeVoices(Closure|array|null $voices = null): FakeVoiceGateway
    {
        return Ai::fakeVoices($voices);
    }

    /**
     * Assert that voices were listed matching a given provider name or truth test.
     */
    public static function assertVoicesListed(Closure|string $callback): void
    {
        Ai::assertVoicesListed($callback);
    }

    /**
     * Assert that voices were not listed matching a given provider name or truth test.
     */
    public static function assertVoicesNotListed(Closure|string $callback): void
    {
        Ai::assertVoicesNotListed($callback);
    }

    /**
     * Assert that no voices were listed.
     */
    public static function assertNoVoicesListed(): void
    {
        Ai::assertNoVoicesListed();
    }

    /**
     * Fake audio generation.
     */
    public static function fake(Closure|array $responses = []): FakeAudioGateway
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
     * Assert that no audio was generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoAudioGenerated();
    }

    /**
     * Assert that a queued audio generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertAudioQueued($callback);
    }

    /**
     * Assert that a queued audio generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertAudioNotQueued($callback);
    }

    /**
     * Assert that no queued audio generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoAudioQueued();
    }

    /**
     * Determine if audio generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::audioIsFaked();
    }
}
