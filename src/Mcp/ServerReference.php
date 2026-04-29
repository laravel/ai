<?php

namespace Laravel\Ai\Mcp;

class ServerReference
{
    /**
     * @param  array<int, string>|null  $only
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $only = null,
    ) {}

    /**
     * Limit the MCP tools exposed from this server.
     *
     * @param  array<int, string>  $toolNames
     */
    public function only(array $toolNames): self
    {
        return new self($this->name, array_values($toolNames));
    }
}
