<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Providers\Tools\ProviderTool;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * @return array<Tool|ProviderTool>
     */
    public function tools(): iterable;
}
