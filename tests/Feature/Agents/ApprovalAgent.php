<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Concerns\HasApprovalFlow;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Feature\Tools\DangerousTool;

class ApprovalAgent implements Agent, HasTools
{
    use Promptable, HasApprovalFlow;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that can perform dangerous operations. Always use the tool when asked to delete files.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [new DangerousTool];
    }
}
