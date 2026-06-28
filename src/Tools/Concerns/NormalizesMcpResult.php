<?php

namespace Laravel\Ai\Tools\Concerns;

trait NormalizesMcpResult
{
    /**
     * Format an MCP error payload into tool output.
     */
    protected function errorMessage(string $text): string
    {
        return $text === ''
            ? 'MCP tool error.'
            : 'MCP tool error: '.$text;
    }

    /**
     * Encode structured MCP content as JSON.
     *
     * @param  array<string, mixed>  $content
     */
    protected function json(array $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
