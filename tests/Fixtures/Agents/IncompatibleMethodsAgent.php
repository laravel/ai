<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxSteps(8)]
#[MaxTokens(1024)]
#[Temperature(0.9)]
class IncompatibleMethodsAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function maxSteps(int $scale): int
    {
        return $scale * 2;
    }

    protected function maxTokens(): int
    {
        return 256;
    }

    private function temperature(): float
    {
        return 0.1;
    }
}
