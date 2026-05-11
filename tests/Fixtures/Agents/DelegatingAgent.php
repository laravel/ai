<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class DelegatingAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a project manager that delegates research tasks to your research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new ResearchAgent,
        ];
    }
}
