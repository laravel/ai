<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Gateway\FakeTranscriptionGateway;
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

        if ($audio instanceof UploadedFile) {
            $audio = new Base64Audio(
                base64_encode($audio->getContent()),
                mime: $audio->getClientMimeType()
            );
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
     * Generate a transcription of the given uploaded file.
     */
    public static function fromUpload(UploadedFile $file): PendingTranscriptionGeneration
    {
        return static::of($file);
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
     * Assert that a queued transcription generation was recorded matching a given truth test.
     */
    public static function assertQueued(Closure $callback): void
    {
        Ai::assertTranscriptionQueued($callback);
    }

    /**
     * Assert that a queued transcription generation was not recorded matching a given truth test.
     */
    public static function assertNotQueued(Closure $callback): void
    {
        Ai::assertTranscriptionNotQueued($callback);
    }

    /**
     * Assert that no queued transcription generations were recorded.
     */
    public static function assertNothingQueued(): void
    {
        Ai::assertNoTranscriptionsQueued();
    }

    /**
     * Determine if transcription generation is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::transcriptionsAreFaked();
    }
}
