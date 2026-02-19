<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Attributes\Thinking;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Thinking(enabled: true, effort: 'high')]
class ThinkingWithEffortAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant with thinking effort.';
    }
}
