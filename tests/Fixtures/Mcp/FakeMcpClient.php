<?php

namespace Tests\Fixtures\Mcp;

class FakeMcpClient
{
    public array $results = [];

    public array $toolCalls = [];

    public int $connects = 0;

    public int $disconnects = 0;

    public function connect(): static
    {
        $this->connects++;

        return $this;
    }

    public function disconnect(): void
    {
        $this->disconnects++;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function callTool(string $name, array $arguments = []): FakeMcpToolResult
    {
        $this->toolCalls[] = ['name' => $name, 'arguments' => $arguments];

        return $this->results[$name];
    }
}
