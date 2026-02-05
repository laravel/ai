<?php

namespace Laravel\Ai\Contracts;

interface NamedTool
{
    /**
     * Get the explicit tool name.
     */
    public function name(): string;
}
