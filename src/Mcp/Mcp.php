<?php

namespace Laravel\Ai\Mcp;

class Mcp
{
    /**
     * Reference a configured MCP server.
     */
    public static function server(string $name): ServerReference
    {
        return new ServerReference($name);
    }
}
