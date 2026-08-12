<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tools\ToolSearch;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * @return array<Tool|ProviderTool|ToolSearch>
     */
    public function tools(): iterable;
}
