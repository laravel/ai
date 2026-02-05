<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeTranslationGateway;
use Laravel\Ai\Prompts\QueuedTranslationPrompt;
use Laravel\Ai\Prompts\TranslationPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeTranslations
{
    /**
     * The fake translation gateway instance.
     */
    protected ?FakeTranslationGateway $fakeTranslationGateway = null;

    /**
     * All of the recorded translation generations.
     */
    protected array $recordedTranslationGenerations = [];

    /**
     * All of the recorded translation generations that were queued.
     */
    protected array $recordedQueuedTranslationGenerations = [];

    /**
     * Fake translation generation.
     */
    public function fakeTranslations(Closure|array $responses = []): FakeTranslationGateway
    {
        return $this->fakeTranslationGateway = new FakeTranslationGateway($responses);
    }

    /**
     * Record a translation generation.
     */
    public function recordTranslationGeneration(TranslationPrompt|QueuedTranslationPrompt $prompt): self
    {
        if ($prompt instanceof QueuedTranslationPrompt) {
            $this->recordedQueuedTranslationGenerations[] = $prompt;
        } else {
            $this->recordedTranslationGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a translation was generated matching a given truth test.
     */
    public function assertTranslationGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranslationGenerations))->filter(function (TranslationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
            'An expected translation generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a translation was not generated matching a given truth test.
     */
    public function assertTranslationNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedTranslationGenerations))->filter(function (TranslationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            'An unexpected translation generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no translations were generated.
     */
    public function assertNoTranslationsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedTranslationGenerations,
            'Unexpected translation generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued translation generation was recorded matching a given truth test.
     */
    public function assertTranslationQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedTranslationGenerations))->filter(function (QueuedTranslationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
            'An expected queued translation generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued translation generation was not recorded matching a given truth test.
     */
    public function assertTranslationNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedTranslationGenerations))->filter(function (QueuedTranslationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            'An unexpected queued translation generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued translation generations were recorded.
     */
    public function assertNoTranslationsQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedTranslationGenerations,
            'Unexpected queued translation generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if translation generation is faked.
     */
    public function translationsAreFaked(): bool
    {
        return $this->fakeTranslationGateway !== null;
    }

    /**
     * Get the fake translation gateway.
     */
    public function fakeTranslationGateway(): ?FakeTranslationGateway
    {
        return $this->fakeTranslationGateway;
    }
}
