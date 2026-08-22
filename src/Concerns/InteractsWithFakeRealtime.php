<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeRealtimeGateway;
use Laravel\Ai\Prompts\RealtimePrompt;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeRealtime
{
    /**
     * The fake realtime gateway instance.
     */
    protected ?FakeRealtimeGateway $fakeRealtimeGateway = null;

    /**
     * All of the recorded realtime session creations.
     */
    protected array $recordedRealtimeSessions = [];

    /**
     * Fake realtime session creation.
     */
    public function fakeRealtime(Closure|array $responses = []): FakeRealtimeGateway
    {
        return $this->fakeRealtimeGateway = new FakeRealtimeGateway($responses);
    }

    /**
     * Record a realtime session creation.
     */
    public function recordRealtimeSessionCreation(RealtimePrompt $prompt): self
    {
        $this->recordedRealtimeSessions[] = $prompt;

        return $this;
    }

    /**
     * Assert that a realtime session was created matching a given truth test.
     */
    public function assertRealtimeSessionCreated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRealtimeSessions))->contains(fn (RealtimePrompt $prompt) => $callback($prompt)),
            'An expected realtime session creation was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that a realtime session was not created matching a given truth test.
     */
    public function assertRealtimeSessionNotCreated(Closure $callback): self
    {
        PHPUnit::assertTrue(
            (new Collection($this->recordedRealtimeSessions))->doesntContain(fn (RealtimePrompt $prompt) => $callback($prompt)),
            'An unexpected realtime session creation was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no realtime sessions were created.
     */
    public function assertNoRealtimeSessionCreated(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedRealtimeSessions,
            'Unexpected realtime sessions were recorded.'
        );

        return $this;
    }

    /**
     * Determine if realtime session creation is faked.
     */
    public function realtimeIsFaked(): bool
    {
        return $this->fakeRealtimeGateway !== null;
    }

    /**
     * Get the fake realtime gateway.
     */
    public function fakeRealtimeGateway(): ?FakeRealtimeGateway
    {
        return $this->fakeRealtimeGateway;
    }
}
