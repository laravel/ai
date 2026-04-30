<?php

namespace Laravel\Ai;

use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Mcp\McpManager;
use Laravel\Ai\Mcp\ServerReference;

/**
 * @method static \Laravel\Ai\Mcp\McpClient instance(?string $name = null)
 * @method static void disconnectAll()
 * @method static void purge(?string $name = null)
 * @method static \Laravel\Ai\Mcp\McpManager extend(string $name, \Closure $callback)
 * @method static \Laravel\Ai\Mcp\McpManager forgetInstance(array|string|null $name = null)
 *
 * @see \Laravel\Ai\Mcp\McpManager
 */
class Mcp extends Facade
{
    /**
     * Build a reference to a configured MCP server.
     */
    public static function server(string $name): ServerReference
    {
        return new ServerReference($name);
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return McpManager::class;
    }
}
