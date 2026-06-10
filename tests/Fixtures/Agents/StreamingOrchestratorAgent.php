<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

class StreamingOrchestratorAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a streaming orchestrator that delegates to the streaming_middle_manager sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new StreamingMiddleManagerAgent,
        ];
    }
}
