<?php

namespace Laravel\Ai\Tools;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

class ToolResolver
{
    /**
     * Resolve the given tool into a native tool instance when needed.
     */
    public static function resolve(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }
}
