<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Sandbox;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Sandbox(approveCommands: false)]
class EphemeralSandboxedAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You work in a sandbox.';
    }
}
