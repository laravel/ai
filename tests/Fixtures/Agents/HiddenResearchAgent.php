<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\HiddenFromClient;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[HiddenFromClient]
class HiddenResearchAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You research the given task and report your findings.';
    }
}
