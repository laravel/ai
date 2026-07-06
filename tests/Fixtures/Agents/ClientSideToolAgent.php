<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ClientLocationTool;
use Tests\Fixtures\Tools\FixedNumberGenerator;

class ClientSideToolAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(public bool $withServerTool = false) {}

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
    public function tools(): iterable
    {
        $tools = [new ClientLocationTool];

        if ($this->withServerTool) {
            $tools[] = new FixedNumberGenerator;
        }

        return $tools;
    }
}
