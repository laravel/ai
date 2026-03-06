<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeAudioGateway;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\QueuedAudioPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeAudio
{
    /**
     * The fake audio gateway instance.
     */
    protected ?FakeAudioGateway $fakeAudioGateway = null;

    /**
     * All of the recorded audio generations.
     */
    protected array $recordedAudioGenerations = [];

    /**
     * All of the recorded audio generations that were queued.
     */
    protected array $recordedQueuedAudioGenerations = [];

    /**
     * Fake audio generation.
     */
    public function fakeAudio(Closure|array $responses = []): FakeAudioGateway
    {
        return $this->fakeAudioGateway = new FakeAudioGateway($responses);
    }

    /**
     * Record an audio generation.
     */
    public function recordAudioGeneration(AudioPrompt|QueuedAudioPrompt $prompt): self
    {
        if ($prompt instanceof QueuedAudioPrompt) {
            $this->recordedQueuedAudioGenerations[] = $prompt;
        } else {
            $this->recordedAudioGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that audio was generated matching a given truth test.
     */
    public function assertAudioGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedAudioGenerations))->contains(function (AudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
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
            (new Collection($this->recordedAudioGenerations))->doesntContain(function (AudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
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
     * Assert that a queued audio generation was recorded matching a given truth test.
     */
    public function assertAudioQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedAudioGenerations))->contains(function (QueuedAudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An expected queued audio generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued audio generation was not recorded matching a given truth test.
     */
    public function assertAudioNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedAudioGenerations))->doesntContain(function (QueuedAudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An unexpected queued audio generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued audio generations were recorded.
     */
    public function assertNoAudioQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedAudioGenerations,
            'Unexpected queued audio generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if audio generation is faked.
     */
    public function audioIsFaked(): bool
    {
        return $this->fakeAudioGateway !== null;
    }

    /**
     * Get the fake audio gateway.
     */
    public function fakeAudioGateway(): ?FakeAudioGateway
    {
        return $this->fakeAudioGateway;
    }
}
