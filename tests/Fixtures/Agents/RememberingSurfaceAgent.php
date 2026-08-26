<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\InteractiveChoiceTool;

class RememberingSurfaceAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You put a choice in front of the user.';
    }

    public function tools(): iterable
    {
        return [new InteractiveChoiceTool];
    }
}
