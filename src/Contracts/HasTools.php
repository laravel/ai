<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\ProviderTool;

interface HasTools
{
    /**
     * Get the tools available to the agent for the given provider.
     *
     * @return array<Tool|ProviderTool>
     */
    public function tools(Lab|string $provider): iterable;
}
