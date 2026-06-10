<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\StreamsActivity;
use Laravel\Ai\Promptable;

class StreamingMiddleManagerAgent implements Agent, CanActAsTool, HasTools, StreamsActivity
{
    use Promptable;

    public function name(): string
    {
        return 'streaming_middle_manager';
    }

    public function description(): string
    {
        return 'Delegate specialized research tasks to streaming research agents.';
    }

    public function instructions(): string
    {
        return 'You are a streaming middle manager that delegates to the streaming_research_agent sub-agent.';
    }

    public function tools(): iterable
    {
        return [
            new StreamingResearchAgent,
        ];
    }
}
