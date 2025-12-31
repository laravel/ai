<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeEmbeddingGateway;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\QueuedEmbeddingsPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeEmbeddings
{
    /**
     * The fake embedding gateway instance.
     */
    protected ?FakeEmbeddingGateway $fakeEmbeddingGateway = null;

    /**
     * All of the recorded embeddings generations.
     */
    protected array $recordedEmbeddingsGenerations = [];

    /**
     * All of the recorded embeddings generations that were queued.
     */
    protected array $recordedQueuedEmbeddingsGenerations = [];

    /**
     * Fake embeddings generation.
     */
    public function fakeEmbeddings(Closure|array $responses = []): FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway = new FakeEmbeddingGateway($responses);
    }

    /**
     * Record an embeddings generation.
     */
    public function recordEmbeddingsGeneration(EmbeddingsPrompt|QueuedEmbeddingsPrompt $prompt): self
    {
        if ($prompt instanceof QueuedEmbeddingsPrompt) {
            $this->recordedQueuedEmbeddingsGenerations[] = $prompt;
        } else {
            $this->recordedEmbeddingsGenerations[] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that embeddings were generated matching a given truth test.
     */
    public function assertEmbeddingsGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedEmbeddingsGenerations))->filter(function (EmbeddingsPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
            'An expected embeddings generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that embeddings were not generated matching a given truth test.
     */
    public function assertEmbeddingsNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedEmbeddingsGenerations))->filter(function (EmbeddingsPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            'An unexpected embeddings generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no embeddings were generated.
     */
    public function assertNoEmbeddingsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedEmbeddingsGenerations,
            'Unexpected embeddings generations were recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was recorded matching a given truth test.
     */
    public function assertEmbeddingsQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedEmbeddingsGenerations))->filter(function (QueuedEmbeddingsPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
            'An expected queued embeddings generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a queued embeddings generation was not recorded matching a given truth test.
     */
    public function assertEmbeddingsNotQueued(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedQueuedEmbeddingsGenerations))->filter(function (QueuedEmbeddingsPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            'An unexpected queued embeddings generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no queued embeddings generations were recorded.
     */
    public function assertNoEmbeddingsQueued(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedEmbeddingsGenerations,
            'Unexpected queued embeddings generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if embeddings generation is faked.
     */
    public function embeddingsAreFaked(): bool
    {
        return $this->fakeEmbeddingGateway !== null;
    }

    /**
     * Get the fake embedding gateway.
     */
    public function fakeEmbeddingGateway(): ?FakeEmbeddingGateway
    {
        return $this->fakeEmbeddingGateway;
    }
}
