<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\AskUser;

class TripPlanner implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): string
    {
        return 'You plan trips. When a preference is unclear, call AskUser instead of guessing.';
    }

    public function tools(): iterable
    {
        return [new AskUser];
    }
}
