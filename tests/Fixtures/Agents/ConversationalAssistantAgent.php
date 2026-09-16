<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;

class ConversationalAssistantAgent extends AssistantAgent implements RemembersConversationsContract
{
    use RemembersConversations;
}
