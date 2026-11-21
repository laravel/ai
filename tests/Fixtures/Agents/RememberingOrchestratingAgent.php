<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;

#[MaxSteps(6)]
class RememberingOrchestratingAgent extends OrchestratingAgent implements Conversational
{
    use RemembersConversations;
}
