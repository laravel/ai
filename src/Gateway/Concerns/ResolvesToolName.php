<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Contracts\Tool;

trait ResolvesToolName
{
    /**
     * Resolve the provider-facing name for the given tool.
     */
    protected function resolveToolName(Tool $tool): string
    {
        return method_exists($tool, 'name') ? $tool->name() : class_basename($tool);
    }
}
