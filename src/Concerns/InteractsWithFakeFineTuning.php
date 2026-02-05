<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Laravel\Ai\Gateway\FakeFineTuningGateway;

trait InteractsWithFakeFineTuning
{
    /**
     * The fake fine-tuning gateway instance.
     */
    protected ?FakeFineTuningGateway $fakeFineTuningGateway = null;

    /**
     * Fake fine-tuning operations.
     */
    public function fakeFineTuning(Closure|array $responses = []): FakeFineTuningGateway
    {
        return $this->fakeFineTuningGateway = new FakeFineTuningGateway($responses);
    }

    /**
     * Determine if fine-tuning is faked.
     */
    public function fineTuningIsFaked(): bool
    {
        return $this->fakeFineTuningGateway !== null;
    }

    /**
     * Get the fake fine-tuning gateway.
     */
    public function fakeFineTuningGateway(): ?FakeFineTuningGateway
    {
        return $this->fakeFineTuningGateway;
    }
}
