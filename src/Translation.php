<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Gateway\FakeTranslationGateway;
use Laravel\Ai\PendingResponses\PendingTranslationGeneration;

class Translation
{
    /**
     * Generate a translation of the given audio.
     */
    public static function of(TranscribableAudio|UploadedFile|string $audio): PendingTranslationGeneration
    {
        if (is_string($audio)) {
            $audio = new Base64Audio($audio);
        } elseif ($audio instanceof UploadedFile) {
            $audio = Base64Audio::fromUpload($audio);
        }

        return new PendingTranslationGeneration($audio);
    }

    /**
     * Generate a translation of the given audio.
     */
    public static function fromBase64(string $base64, ?string $mime = null): PendingTranslationGeneration
    {
        return static::of(new Base64Audio($base64, $mime));
    }

    /**
     * Generate a translation of the audio at the given path.
     */
    public static function fromPath(string $path, ?string $mime = null): PendingTranslationGeneration
    {
        return static::of(new LocalAudio($path, $mime));
    }

    /**
     * Generate a translation of the given stored audio.
     */
    public static function fromStorage(string $path, ?string $disk = null): PendingTranslationGeneration
    {
        return static::of(new StoredAudio($path, $disk));
    }

    /**
     * Generate a translation of the given uploaded file.
     */
    public static function fromUpload(UploadedFile $file): PendingTranslationGeneration
    {
        return static::of($file);
    }

    /**
     * Fake translation generation.
     */
    public static function fake(Closure|array $responses = []): FakeTranslationGateway
    {
        return Ai::fakeTranslations($responses);
    }

    /**
     * Assert that a translation was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertTranslationGenerated($callback);
    }

    /**
     * Assert that a translation was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertTranslationNotGenerated($callback);
    }

    /**
     * Assert that no translations were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoTranslationsGenerated();
    }

    /**
     * Assert that a queued translation generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertTranslationQueued($callback);
    }

    /**
     * Assert that a queued translation generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertTranslationNotQueued($callback);
    }

    /**
     * Assert that no queued translation generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoTranslationsQueued();
    }

    /**
     * Determine if translation generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::translationsAreFaked();
    }
}
