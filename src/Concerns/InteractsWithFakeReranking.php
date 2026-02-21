<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeRerankingGateway;
use Laravel\Ai\Prompts\RerankingPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeReranking
{
    /**
     * The fake reranking gateway instance.
     */
    protected ?FakeRerankingGateway $fakeRerankingGateway = null;

    /**
     * All of the recorded rerankings.
     */
    protected array $recordedRerankings = [];

    /**
     * Fake reranking operations.
     */
    public function fakeReranking(Closure|array $responses = []): FakeRerankingGateway
    {
        return $this->fakeRerankingGateway = new FakeRerankingGateway($responses);
    }

    /**
     * Record a reranking.
     */
    public function recordReranking(RerankingPrompt $prompt): self
    {
        $this->recordedRerankings[] = $prompt;

        return $this;
    }

    /**
     * Assert that a reranking was performed matching a given truth test.
     */
    public function assertReranked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRerankings))->contains(function (RerankingPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An expected reranking was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a reranking was not performed matching a given truth test.
     */
    public function assertNotReranked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRerankings))->doesntContain(function (RerankingPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An unexpected reranking was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no rerankings were performed.
     */
    public function assertNothingReranked(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedRerankings,
            'Unexpected rerankings were recorded.'
        );

        return $this;
    }

    /**
     * Determine if reranking is faked.
     */
    public function rerankingIsFaked(): bool
    {
        return $this->fakeRerankingGateway !== null;
    }

    /**
     * Get the fake reranking gateway.
     */
    public function fakeRerankingGateway(): ?FakeRerankingGateway
    {
        return $this->fakeRerankingGateway;
    }
}
