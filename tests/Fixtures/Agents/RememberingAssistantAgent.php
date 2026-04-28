<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class RememberingAssistantAgent implements Agent
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
