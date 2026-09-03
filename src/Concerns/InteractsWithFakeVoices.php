<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\FakeVoiceGateway;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeVoices
{
    /**
     * The fake voice gateway instance.
     */
    protected ?FakeVoiceGateway $fakeVoiceGateway = null;

    /**
     * All of the recorded voice listings.
     */
    protected array $recordedVoiceListings = [];

    /**
     * Fake voice listing.
     */
    public function fakeVoices(Closure|array|null $voices = null): FakeVoiceGateway
    {
        return $this->fakeVoiceGateway = new FakeVoiceGateway($voices);
    }

    /**
     * Record a voice listing.
     */
    public function recordVoiceListing(string $provider): self
    {
        $this->recordedVoiceListings[] = $provider;

        return $this;
    }

    /**
     * Assert that voices were listed matching a given provider name or truth test.
     */
    public function assertVoicesListed(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $provider = $callback;
            $callback = fn (string $name): bool => $name === $provider;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedVoiceListings))->contains(fn (string $name) => $callback($name)),
            'An expected voice listing was not recorded.'
        );

        return $this;
    }

    /**
     * Assert that voices were not listed matching a given provider name or truth test.
     */
    public function assertVoicesNotListed(Closure|string $callback): self
    {
        if (is_string($callback)) {
            $provider = $callback;
            $callback = fn (string $name): bool => $name === $provider;
        }

        PHPUnit::assertTrue(
            (new Collection($this->recordedVoiceListings))->doesntContain(fn (string $name) => $callback($name)),
            'An unexpected voice listing was recorded.'
        );

        return $this;
    }

    /**
     * Assert that no voices were listed.
     */
    public function assertNoVoicesListed(): self
    {
        PHPUnit::assertEmpty(
            $this->recordedVoiceListings,
            'Unexpected voice listings were recorded.'
        );

        return $this;
    }

    /**
     * Determine if voice listing is faked.
     */
    public function voicesAreFaked(): bool
    {
        return $this->fakeVoiceGateway !== null;
    }

    /**
     * Get the fake voice gateway instance.
     */
    public function fakeVoiceGateway(): ?FakeVoiceGateway
    {
        return $this->fakeVoiceGateway;
    }
}
