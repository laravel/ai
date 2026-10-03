<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Sandbox;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

#[Sandbox(approveCommands: false)]
class SandboxedAgent implements Agent, Conversational
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You work in a sandbox.';
    }
}
