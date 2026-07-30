<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\NestedObjectTool;

class NestedObjectToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(Lab|string $provider): iterable
    {
        return [
            new NestedObjectTool,
        ];
    }
}
