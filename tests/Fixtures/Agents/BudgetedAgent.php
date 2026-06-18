<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxCost;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxCost(0.005)]
class BudgetedAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a budgeted assistant.';
    }
}
