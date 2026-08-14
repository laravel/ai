<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\AgentCallingTool;

class DelegatingViaCustomToolAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You delegate research using your tool.';
    }

    public function tools(): iterable
    {
        return [
            new AgentCallingTool,
        ];
    }
}
