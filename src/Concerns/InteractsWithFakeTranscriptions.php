<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

trait InteractsWithFakeTranscriptions
{
    /**
     * Indicates if transcription generation is faked.
     */
    protected bool $transcriptionsFaked = false;

    /**
     * The faked transcription responses.
     */
    protected Closure|array $fakeTranscriptionResponses = [];

    /**
     * All of the recorded transcription generations.
     */
    protected array $recordedTranscriptionGenerations = [];

    /**
     * The current index of the faked transcription responses.
     */
    protected int $fakeTranscriptionResponseIndex = 0;

    /**
     * Indicates if stray transcription generations should be prevented.
     */
    protected bool $preventStrayTranscriptionGenerations = false;

    /**
     * Fake transcription generation.
     */
    public function fakeTranscriptions(Closure|array $responses = []): self
    {
        $this->transcriptionsFaked = true;
        $this->fakeTranscriptionResponses = $responses;

        return $this;
    }

    /**
     * Record a transcription generation.
     */
    public function recordTranscriptionGeneration(TranscribableAudio|UploadedFile $audio, ?string $language, bool $diarize): self
    {
        $this->recordedTranscriptionGenerations[] = [
            'audio' => $audio,
            'language' => $language,
            'diarize' => $diarize,
        ];

        return $this;
    }

    /**
     * Get the next fake transcription response.
     */
    public function nextFakeTranscriptionResponse(
        TranscribableAudio|UploadedFile $audio,
        ?string $language,
        bool $diarize): TranscriptionResponse
    {
        $response = is_array($this->fakeTranscriptionResponses)
            ? ($this->fakeTranscriptionResponses[$this->fakeTranscriptionResponseIndex] ?? null)
            : call_user_func($this->fakeTranscriptionResponses, $audio, $language, $diarize);

        $this->fakeTranscriptionResponseIndex++;

        return $this->marshalTranscriptionResponse(
            $response, $audio, $language, $diarize
        );
    }

    /**
     * Marshal the given response into a TranscriptionResponse instance.
     */
    protected function marshalTranscriptionResponse(
        mixed $response,
        TranscribableAudio|UploadedFile $audio,
        ?string $language,
        bool $diarize): TranscriptionResponse
    {
        if ($response instanceof Closure) {
            $response = $response($audio, $language, $diarize);
        }

        if (is_null($response)) {
            if ($this->preventStrayTranscriptionGenerations) {
                throw new RuntimeException('Attempted transcription generation without a fake response.');
            }

            $response = 'Fake transcription text.';
        }

        if (is_string($response)) {
            return new TranscriptionResponse(
                $response,
                new Collection([
                    new TranscriptionSegment($response, 'Speaker 1', 0.0, 1.0),
                ]),
                new Usage,
                new Meta,
            );
        }

        return $response;
    }

    /**
     * Assert that a transcription was generated matching a given truth test.
     */
    public function assertTranscriptionGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranscriptionGenerations))->filter(function ($generation) use ($callback) {
                return $callback($generation['audio'], $generation['language'], $generation['diarize']);
            })->count() > 0,
            'An expected transcription generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a transcription was not generated matching a given truth test.
     */
    public function assertTranscriptionNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranscriptionGenerations))->filter(function ($generation) use ($callback) {
                return $callback($generation['audio'], $generation['language'], $generation['diarize']);
            })->count() === 0,
            'An unexpected transcription generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no transcriptions were generated.
     */
    public function assertNoTranscriptionsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedTranscriptionGenerations,
            'Unexpected transcription generations were recorded.'
        );

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any transcription generation is not faked.
     */
    public function preventStrayTranscriptionGenerations(bool $prevent = true): self
    {
        $this->preventStrayTranscriptionGenerations = $prevent;

        return $this;
    }

    /**
     * Determine if transcription generation is faked.
     */
    public function areTranscriptionsFaked(): bool
    {
        return $this->transcriptionsFaked;
    }
}
