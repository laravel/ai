<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

class RememberingDelegatingAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You are a manager that delegates tasks to your remembering_sub_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new RememberingSubAgent,
        ];
    }
}
