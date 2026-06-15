<?php

namespace Laravel\Ai\Contracts;

interface HasTools
{
    /**
     * Get the tools available to the agent.
     *
     * Items may include Tool instances, ProviderTool instances (e.g. WebSearch),
     * Agent instances (treated as sub-agents), raw MCP client/server primitives,
     * MCP server classes/instances, or McpServer wrappers. The SDK resolves and
     * wraps values as needed before passing them to the model.
     *
     * @return iterable
     */
    public function tools(): iterable;
}
