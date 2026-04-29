<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Mcp\ServerReference;

interface HasMcpServers
{
    /**
     * Get the MCP servers available to the agent.
     *
     * @return array<string|ServerReference>
     */
    public function mcpServers(): array;
}
