<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class OrchestratorAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    public function name(): string
    {
        return 'orchestrator';
    }

    public function description(): string
    {
        return 'Orchestrate complex tasks by delegating to specialized sub-agents.';
    }

    public function instructions(): string
    {
        return 'You are an orchestrator that breaks down complex tasks and delegates to the middle_manager sub-agent.';
    }

    public function tools(Lab|string $provider): iterable
    {
        return [
            new MiddleManagerAgent,
        ];
    }
}
