<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class RememberingMultiStepApprovableAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates a number, then generates a gated number.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [new FixedNumberGenerator, new ApprovableNumberGenerator];
    }
}
