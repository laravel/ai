<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
class MethodOverridesAttributeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(): int
    {
        return 1;
    }

    public function maxTokens(): int
    {
        return 512;
    }

    public function temperature(): float
    {
        return 0.2;
    }
}
