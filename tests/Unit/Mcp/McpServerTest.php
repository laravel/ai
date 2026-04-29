<?php

namespace Tests\Unit\Mcp;

use Laravel\Ai\Mcp\McpServer;
use Laravel\Ai\Mcp\Protocol\JsonRpc;
use Laravel\Ai\Mcp\Transports\StdioTransport;
use PHPUnit\Framework\TestCase;

class McpServerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        JsonRpc::resetIdCounter();
    }

    public function test_it_discovers_paginated_stdio_tools_and_calls_them(): void
    {
        $server = new McpServer('test', new StdioTransport([
            PHP_BINARY,
            __DIR__.'/../../Fixtures/Mcp/stdio-server.php',
        ]));

        try {
            $tools = $server->tools();

            $this->assertCount(2, $tools);
            $this->assertSame('say_hello', $tools[0]->name);
            $this->assertSame('answer', $tools[1]->name);
            $this->assertSame(['tools' => ['listChanged' => true]], $server->capabilities());

            $result = $server->callTool('say_hello', ['name' => 'Taylor']);

            $this->assertFalse($result->isError);
            $this->assertSame('Hello, Taylor', $result->toText());
            $this->assertSame(['value' => 'Hello, Taylor'], $result->structuredContent);
        } finally {
            $server->disconnect();
        }
    }
}
