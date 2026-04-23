<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\RandomNumberGenerator;

class DelegatingAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a project manager that delegates research tasks to your sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator,
            new SubAgent,
        ];
    }
}
