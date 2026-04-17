<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Contracts\Tool;

trait ResolvesToolName
{
    /**
     * Resolve the provider-facing name for the given tool.
     *
     * Tools that declare a public `name()` method override the default
     * basename-based naming. This allows a single tool class to represent
     * many distinct tools (adapter-style tools that dispatch to different
     * targets per instance) without colliding on the name the provider sees.
     */
    protected function resolveToolName(Tool $tool): string
    {
        return method_exists($tool, 'name') ? $tool->name() : class_basename($tool);
    }
}
