<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\CodeMode\CodeMode;
use Laravel\Ai\Providers\Tools\ProviderTool;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * @return array<Tool|ProviderTool|CodeMode>
     */
    public function tools(): iterable;
}
