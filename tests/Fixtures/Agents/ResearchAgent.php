<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasToolMetadata;
use Laravel\Ai\Promptable;

class ResearchAgent implements Agent, HasToolMetadata
{
    use Promptable;

    public function name(): string
    {
        return 'research_agent';
    }

    public function description(): string
    {
        return 'Research a topic in depth and return a summary.';
    }

    public function instructions(): string
    {
        return 'You are a research agent. Summarize your findings concisely.';
    }
}
