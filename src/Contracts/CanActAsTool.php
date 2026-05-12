<?php

namespace Laravel\Ai\Contracts;

use Stringable;

interface CanActAsTool
{
    /**
     * Get the name of the tool.
     */
    public function name(): string;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string;
}
