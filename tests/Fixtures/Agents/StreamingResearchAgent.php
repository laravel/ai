<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\StreamsActivity;
use Laravel\Ai\Promptable;

class StreamingResearchAgent implements Agent, CanActAsTool, StreamsActivity
{
    use Promptable;

    public function name(): string
    {
        return 'streaming_research_agent';
    }

    public function description(): string
    {
        return 'Research a topic in depth and stream activity while working.';
    }

    public function instructions(): string
    {
        return 'You are a streaming research agent. Summarize your findings concisely.';
    }
}
