<?php

namespace Laravel\Ai\Mcp;

class McpToolAlias
{
    /**
     * Create a provider-safe alias for an MCP server tool.
     */
    public static function make(string $server, string $tool): string
    {
        $prefix = static::sanitize($server);
        $name = static::sanitize($tool);
        $hash = substr(sha1($server."\0".$tool), 0, 8);
        $maxBaseLength = 64 - strlen($hash) - 3;
        $base = substr($prefix.'__'.$name, 0, $maxBaseLength);
        $base = trim($base, '_-') ?: 'mcp_tool';

        return $base.'__'.$hash;
    }

    /**
     * Sanitize a name for provider tool declarations.
     */
    protected static function sanitize(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: '';
        $value = preg_replace('/_+/', '_', $value) ?: '';

        return trim($value, '_-') ?: 'mcp';
    }
}
