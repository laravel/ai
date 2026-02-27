<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasSubAgents;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Feature\Tools\FixedNumberGenerator;

class PrimaryWithSubAgentsAgent implements Agent, HasSubAgents, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a primary agent that can delegate to subagents.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }

    public function subAgents(): array
    {
        return [new DeterministicSubAgent];
    }
}
