<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;

trait InteractsWithFakeAudio
{
    /**
     * Indicates if audio generation is faked.
     */
    protected bool $audioFaked = false;

    /**
     * The faked audio responses.
     */
    protected Closure|array $fakeAudioResponses = [];

    /**
     * All of the recorded audio generations.
     */
    protected array $recordedAudioGenerations = [];

    /**
     * The current index of the faked audio responses.
     */
    protected int $fakeAudioResponseIndex = 0;

    /**
     * Indicates if stray audio generations should be prevented.
     */
    protected bool $preventStrayAudioGenerations = false;

    /**
     * Fake audio generation.
     */
    public function fakeAudio(Closure|array $responses = []): self
    {
        $this->audioFaked = true;
        $this->fakeAudioResponses = $responses;

        return $this;
    }

    /**
     * Record an audio generation.
     */
    public function recordAudioGeneration(string $text, string $voice, ?string $instructions): self
    {
        $this->recordedAudioGenerations[] = [
            'text' => $text,
            'voice' => $voice,
            'instructions' => $instructions,
        ];

        return $this;
    }

    /**
     * Get the next fake audio response.
     */
    public function nextFakeAudioResponse(string $text, string $voice, ?string $instructions): AudioResponse
    {
        $response = is_array($this->fakeAudioResponses)
            ? ($this->fakeAudioResponses[$this->fakeAudioResponseIndex] ?? null)
            : call_user_func($this->fakeAudioResponses, $text, $voice, $instructions);

        $this->fakeAudioResponseIndex++;

        return $this->marshalAudioResponse($response, $text, $voice, $instructions);
    }

    /**
     * Marshal the given response into an AudioResponse instance.
     */
    protected function marshalAudioResponse(mixed $response, string $text, string $voice, ?string $instructions): AudioResponse
    {
        if ($response instanceof Closure) {
            $response = $response($text, $voice, $instructions);
        }

        if (is_null($response)) {
            if ($this->preventStrayAudioGenerations) {
                throw new RuntimeException('Attempted audio generation without a fake response.');
            }

            $response = base64_encode('fake-audio-content');
        }

        if (is_string($response)) {
            return new AudioResponse($response, new Meta);
        }

        return $response;
    }

    /**
     * Assert that audio was generated matching a given truth test.
     */
    public function assertAudioGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedAudioGenerations))->filter(function (array $generation) use ($callback) {
                return $callback($generation['text'], $generation['voice'], $generation['instructions']);
            })->count() > 0,
            'An expected audio generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that audio was not generated matching a given truth test.
     */
    public function assertAudioNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedAudioGenerations))->filter(function (array $generation) use ($callback) {
                return $callback($generation['text'], $generation['voice'], $generation['instructions']);
            })->count() === 0,
            'An unexpected audio generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no audio was generated.
     */
    public function assertNoAudioGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedAudioGenerations,
            'Unexpected audio generations were recorded.'
        );

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any audio generation is not faked.
     */
    public function preventStrayAudioGenerations(bool $prevent = true): self
    {
        $this->preventStrayAudioGenerations = $prevent;

        return $this;
    }

    /**
     * Determine if audio generation is faked.
     */
    public function isAudioFaked(): bool
    {
        return $this->audioFaked;
    }
}
