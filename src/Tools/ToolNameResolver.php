<?php

namespace Laravel\Ai\Tools;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;

class ToolNameResolver
{
    public static function resolve(Tool|ProviderTool $tool): string
    {
        return $tool instanceof Tool && is_callable([$tool, 'name']) ? $tool->name() : class_basename($tool);
    }
}
