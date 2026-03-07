<?php

namespace Tests\Feature\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class MultiInstructionAgent implements Agent
{
    use Promptable;

    public function instructions(): array
    {
        return ['You are a helpful assistant.', 'The user is John.'];
    }
}
