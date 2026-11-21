<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[MaxSteps(6)]
class OrchestratingAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Create a new agent instance.
     */
    public function __construct(public array $tools = [])
    {
        //
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You break tasks down and delegate each of them to the sub-agent best suited to it.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
