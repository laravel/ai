<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;

class RememberingAssistantAgent extends AssistantAgent
{
    use RemembersConversations;
}
