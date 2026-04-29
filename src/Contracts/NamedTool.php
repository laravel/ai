<?php

namespace Laravel\Ai\Contracts;

interface NamedTool
{
    /**
     * Get the provider-facing tool name.
     */
    public function name(): string;
}
