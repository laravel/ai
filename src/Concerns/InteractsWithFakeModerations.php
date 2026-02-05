<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeModerationGateway;
use Laravel\Ai\Prompts\ModerationPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeModerations
{
    /**
     * The fake moderation gateway instance.
     */
    protected ?FakeModerationGateway $fakeModerationGateway = null;

    /**
     * All of the recorded moderation generations.
     */
    protected array $recordedModerationGenerations = [];

    /**
     * Fake moderation generation.
     */
    public function fakeModerations(Closure|array $responses = []): FakeModerationGateway
    {
        return $this->fakeModerationGateway = new FakeModerationGateway($responses);
    }

    /**
     * Record a moderation generation.
     */
    public function recordModerationGeneration(ModerationPrompt $prompt): self
    {
        $this->recordedModerationGenerations[] = $prompt;

        return $this;
    }

    /**
     * Assert that a moderation was generated matching a given truth test.
     */
    public function assertModerationGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedModerationGenerations))->filter(function (ModerationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
            'An expected moderation generation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a moderation was not generated matching a given truth test.
     */
    public function assertModerationNotGenerated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedModerationGenerations))->filter(function (ModerationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            'An unexpected moderation generation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no moderations were generated.
     */
    public function assertNoModerationsGenerated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedModerationGenerations,
            'Unexpected moderation generations were recorded.'
        );

        return $this;
    }

    /**
     * Determine if moderation generation is faked.
     */
    public function moderationsAreFaked(): bool
    {
        return $this->fakeModerationGateway !== null;
    }

    /**
     * Get the fake moderation gateway.
     */
    public function fakeModerationGateway(): ?FakeModerationGateway
    {
        return $this->fakeModerationGateway;
    }
}
