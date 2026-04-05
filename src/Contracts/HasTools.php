<?php

namespace Laravel\Ai\Contracts;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * @return array<Tool|\Laravel\Ai\Providers\Tools\ProviderTool>
     */
    public function tools(): iterable;
}
