<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\ActsAsTool;
use Laravel\Ai\Promptable;

class MiddleManagerAgent implements Agent, HasTools, ActsAsTool
{
    use Promptable;

    public function name(): string
    {
        return 'middle_manager';
    }

    public function description(): string
    {
        return 'Delegate specialized research tasks to the research agent.';
    }

    public function instructions(): string
    {
        return 'You are a middle manager that breaks down tasks and delegates to the research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new ResearchAgent,
        ];
    }
}
