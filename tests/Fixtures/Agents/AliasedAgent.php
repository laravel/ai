<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\Alias;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Alias('aliased-agent')]
class AliasedAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
