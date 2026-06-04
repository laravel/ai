<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class InvocationContextParentAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You delegate work to the context_child sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new InvocationContextChildAgent,
        ];
    }
}
