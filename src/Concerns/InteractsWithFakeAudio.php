<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeAudioGateway;
use Laravel\Ai\Prompts\AudioPrompt;
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
     * Fake audio generation.
     */
    public function fakeAudio(Closure|array $responses = []): FakeAudioGateway
    {
        return $this->fakeAudioGateway = new FakeAudioGateway($responses);
    }

    /**
     * Record an audio generation.
     */
    public function recordAudioGeneration(AudioPrompt $prompt): self
    {
        $this->recordedAudioGenerations[] = $prompt;

        return $this;
    }

    /**
     * Assert that audio was generated matching a given truth test.
     */
    public function assertAudioGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedAudioGenerations))->filter(function (AudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
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
            (new Collection($this->recordedAudioGenerations))->filter(function (AudioPrompt $prompt) use ($callback) {
                return $callback($prompt);
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
