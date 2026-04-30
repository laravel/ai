<?php

namespace Tests\Unit\Mcp;

use Laravel\Ai\Exceptions\McpException;
use Laravel\Ai\Mcp\Protocol\JsonRpc;
use PHPUnit\Framework\TestCase;

class JsonRpcTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        JsonRpc::resetIdCounter();
    }

    public function test_it_builds_requests(): void
    {
        $request = JsonRpc::request('tools/list', ['cursor' => 'next']);

        $this->assertSame('2.0', $request['jsonrpc']);
        $this->assertSame(1, $request['id']);
        $this->assertSame('tools/list', $request['method']);
        $this->assertSame(['cursor' => 'next'], $request['params']);
    }

    public function test_it_builds_notifications(): void
    {
        $notification = JsonRpc::notification('notifications/initialized');

        $this->assertSame('2.0', $notification['jsonrpc']);
        $this->assertSame('notifications/initialized', $notification['method']);
        $this->assertArrayNotHasKey('id', $notification);
    }

    public function test_it_throws_on_json_rpc_errors(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('MCP JSON-RPC error [-32602]: Invalid params');

        JsonRpc::result([
            'jsonrpc' => '2.0',
            'id' => 1,
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params',
            ],
        ]);
    }
}
