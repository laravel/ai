<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[Provider('anthropic')]
class AttributeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
