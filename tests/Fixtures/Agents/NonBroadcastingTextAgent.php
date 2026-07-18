<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\TextDelta;

#[WithoutBroadcasting(TextDelta::class)]
class NonBroadcastingTextAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
