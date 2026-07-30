<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\RateLimitedNumberGenerator;

class RateLimitedToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers using the tool available to you.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(Lab|string $provider): iterable
    {
        return [new RateLimitedNumberGenerator];
    }
}
