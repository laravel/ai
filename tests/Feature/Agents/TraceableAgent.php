<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Concerns\Traceable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class TraceableAgent implements Agent
{
    use Promptable, Traceable;

    protected ?string $driver = null;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tracingDriver(): ?string
    {
        return $this->driver;
    }

    public function useTracingDriver(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }
}
