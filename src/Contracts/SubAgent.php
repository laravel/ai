<?php

namespace Laravel\Ai\Contracts;

use Stringable;

interface SubAgent extends Agent
{
    /**
     * Get the subagent name.
     */
    public function name(): string;

    /**
     * Get the description of the subagent's purpose.
     */
    public function description(): Stringable|string;
}
