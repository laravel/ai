<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[TopP(0.8)]
#[Provider('anthropic')]
class AttributeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
