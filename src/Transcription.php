<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Gateway\FakeTranscriptionGateway;
use Laravel\Ai\Messages\Attachments\Base64Audio;
use Laravel\Ai\Messages\Attachments\LocalAudio;
use Laravel\Ai\Messages\Attachments\StoredAudio;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;

class Transcription
{
    /**
     * Generate a transcription of the given audio.
     */
    public static function of(TranscribableAudio|UploadedFile|string $audio): PendingTranscriptionGeneration
    {
        if (is_string($audio)) {
            $audio = new Base64Audio($audio);
        }

        return new PendingTranscriptionGeneration($audio);
    }

    /**
     * Generate a transcription of the given audio.
     */
    public static function fromBase64(string $base64, ?string $mime = null): PendingTranscriptionGeneration
    {
        return static::of(new Base64Audio($base64, $mime));
    }

    /**
     * Generate a transcription of the audio at the given path.
     */
    public static function fromPath(string $path, ?string $mime = null): PendingTranscriptionGeneration
    {
        return static::of(new LocalAudio($path, $mime));
    }

    /**
     * Generate a transcription of the given stored audio.
     */
    public static function fromStorage(string $path, ?string $disk = null): PendingTranscriptionGeneration
    {
        return static::of(new StoredAudio($path, $disk));
    }

    /**
     * Fake transcription generation.
     */
    public static function fake(Closure|array $responses = []): FakeTranscriptionGateway
    {
        return Ai::fakeTranscriptions($responses);
    }

    /**
     * Assert that a transcription was generated matching a given truth test.
     */
    public static function assertGenerated(Closure $callback): void
    {
        Ai::assertTranscriptionGenerated($callback);
    }

    /**
     * Assert that a transcription was not generated matching a given truth test.
     */
    public static function assertNotGenerated(Closure $callback): void
    {
        Ai::assertTranscriptionNotGenerated($callback);
    }

    /**
     * Assert that no transcriptions were generated.
     */
    public static function assertNothingGenerated(): void
    {
        Ai::assertNoTranscriptionsGenerated();
    }

    /**
     * Determine if transcription generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::transcriptionsAreFaked();
    }
}
