<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeModerationGateway;
use Laravel\Ai\Prompts\ModerationPrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeModeration
{
    /**
     * The fake moderation gateway instance.
     */
    protected ?FakeModerationGateway $fakeModerationGateway = null;

    /**
     * All of the recorded moderations.
     */
    protected array $recordedModerations = [];

    /**
     * Fake moderation operations.
     */
    public function fakeModeration(Closure|array $responses = []): FakeModerationGateway
    {
        return $this->fakeModerationGateway = new FakeModerationGateway($responses);
    }

    /**
     * Record a moderation.
     */
    public function recordModeration(ModerationPrompt $prompt): self
    {
        $this->recordedModerations[] = $prompt;

        return $this;
    }

    /**
     * Assert that a moderation was performed matching a given truth test.
     */
    public function assertChecked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedModerations))->contains(function (ModerationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An expected moderation check was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a moderation was not performed matching a given truth test.
     */
    public function assertNotChecked(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedModerations))->doesntContain(function (ModerationPrompt $prompt) use ($callback) {
                return $callback($prompt);
            }),
            'An unexpected moderation check was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no moderations were performed.
     */
    public function assertNothingChecked(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedModerations,
            'Unexpected moderation checks were recorded.'
        );

        return $this;
    }

    /**
     * Determine if moderation is faked.
     */
    public function moderationIsFaked(): bool
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
