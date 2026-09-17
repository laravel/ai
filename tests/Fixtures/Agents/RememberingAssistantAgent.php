<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;

class RememberingAssistantAgent extends AssistantAgent implements Conversational
{
    use RemembersConversations;
}
