<?php

namespace Laravel\Ai\Contracts;

interface HasSubAgents
{
    /**
     * Get the subagents available to the primary agent.
     *
     * @return SubAgent[]
     */
    public function subAgents(): array;
}
