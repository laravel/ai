<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Provider;

#[Provider(['primary', 'backup'])]
class FailoverAssistantAgent extends AssistantAgent
{
    //
}
