<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasSubAgents;
use Laravel\Ai\Promptable;

#[MaxSteps(4)]
#[Temperature(0.0)]
class OllamaDelegatingAgent implements Agent, HasSubAgents
{
    use Promptable;

    public function instructions(): string
    {
        return 'You must call the called_tracking_subagent tool exactly once for any user task. Pass through the exact task string and return only the tool output.';
    }

    public function subAgents(): array
    {
        return [new CalledTrackingSubAgent];
    }
}
