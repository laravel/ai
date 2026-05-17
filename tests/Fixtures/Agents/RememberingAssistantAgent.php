<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\RemembersConversation;

class RememberingAssistantAgent extends AssistantAgent implements RemembersConversation
{
    use RemembersConversations;
}
