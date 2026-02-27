<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class MethodOptionsAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(): int
    {
        return 3;
    }

    public function maxTokens(): int
    {
        return 2048;
    }

    public function temperature(): float
    {
        return 0.5;
    }
}
