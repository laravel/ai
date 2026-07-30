<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebFetch;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class ProviderToolsAgent implements Agent, HasTools
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
     * Get the tools available to the agent for the given provider.
     */
    public function tools(Lab|string $provider): iterable
    {
        return match ($provider) {
            Lab::Anthropic => [new FixedNumberGenerator, new WebFetch],
            default => [new FixedNumberGenerator],
        };
    }
}
