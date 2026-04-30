<?php

namespace Tests\Unit\Mcp;

use Laravel\Ai\Mcp\McpServer;
use Laravel\Ai\Mcp\Protocol\JsonRpc;
use Laravel\Ai\Mcp\Transports\StdioTransport;
use Laravel\Ai\Contracts\Mcp\McpTransport;
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

    public function test_it_can_forget_cached_tools(): void
    {
        $transport = new class implements McpTransport
        {
            public int $toolsListCalls = 0;

            public function open(): void {}

            public function send(array $request): array
            {
                if ($request['method'] === 'initialize') {
                    return [
                        'jsonrpc' => '2.0',
                        'id' => $request['id'],
                        'result' => [
                            'protocolVersion' => McpServer::ProtocolVersion,
                            'capabilities' => ['tools' => []],
                        ],
                    ];
                }

                if ($request['method'] === 'tools/list') {
                    $this->toolsListCalls++;

                    return [
                        'jsonrpc' => '2.0',
                        'id' => $request['id'],
                        'result' => [
                            'tools' => [[
                                'name' => 'tool_'.$this->toolsListCalls,
                                'description' => '',
                                'inputSchema' => ['type' => 'object'],
                            ]],
                        ],
                    ];
                }

                return ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => []];
            }

            public function notify(array $notification): void {}

            public function close(): void {}

            public function isOpen(): bool
            {
                return true;
            }
        };

        $server = new McpServer('test', $transport);

        $this->assertSame('tool_1', $server->tools()[0]->name);
        $this->assertSame('tool_1', $server->tools()[0]->name);
        $this->assertSame(1, $transport->toolsListCalls);

        $server->forgetTools();

        $this->assertSame('tool_2', $server->tools()[0]->name);
        $this->assertSame(2, $transport->toolsListCalls);
    }
}
