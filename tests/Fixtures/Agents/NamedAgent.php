<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Name;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Name('My Custom Agent')]
class NamedAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
