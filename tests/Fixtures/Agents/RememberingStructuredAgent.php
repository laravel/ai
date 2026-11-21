<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;

class RememberingStructuredAgent extends StructuredAgent
{
    use RemembersConversations;
}
