<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

class RememberingSubAgent implements Agent, CanActAsTool, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function name(): string
    {
        return 'remembering_sub_agent';
    }

    public function description(): string
    {
        return 'A specialist sub-agent that continues the parent conversation.';
    }

    public function instructions(): string
    {
        return 'You are a specialist that answers using the shared conversation history.';
    }
}
