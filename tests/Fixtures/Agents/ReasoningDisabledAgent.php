<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Reasoning;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Reasoning(false)]
class ReasoningDisabledAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
