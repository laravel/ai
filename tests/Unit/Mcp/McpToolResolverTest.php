<?php

use Laravel\Ai\Mcp\Mcp;
use Laravel\Ai\Mcp\McpManager;
use Laravel\Ai\Mcp\McpServer;
use Laravel\Ai\Mcp\McpTool;
use Laravel\Ai\Mcp\McpToolResolver;
use Laravel\Ai\Mcp\Tool;
use Laravel\Ai\Mcp\Transports\McpTransport;

function fakeMcpServer(string $name, array $toolDefinitions): McpServer
{
    return new class($name, $toolDefinitions) extends McpServer
    {
        public function __construct(string $name, protected array $toolDefinitions)
        {
            parent::__construct($name, new class implements McpTransport
            {
                public function open(): void {}

                public function send(array $request): array
                {
                    return ['jsonrpc' => '2.0', 'id' => $request['id'] ?? null, 'result' => []];
                }

                public function notify(array $notification): void {}

                public function close(): void {}

                public function isOpen(): bool
                {
                    return true;
                }
            });
        }

        public function tools(): array
        {
            return array_map(fn (array $tool) => Tool::fromArray($tool), $this->toolDefinitions);
        }

        public function disconnect(): void {}
    };
}

test('resolver returns adapters for all tools when no allowlist is given', function () {
    $server = fakeMcpServer('test', [
        ['name' => 'alpha', 'description' => '', 'inputSchema' => ['type' => 'object']],
        ['name' => 'beta', 'description' => '', 'inputSchema' => ['type' => 'object']],
    ]);

    $manager = Mockery::mock(McpManager::class);
    $manager->shouldReceive('server')->with('test')->andReturn($server);

    $tools = (new McpToolResolver($manager))->tools(['test']);

    expect($tools)->toHaveCount(2)
        ->and($tools[0])->toBeInstanceOf(McpTool::class)
        ->and($tools[0]->mcpName())->toBe('alpha')
        ->and($tools[1]->mcpName())->toBe('beta');
});

test('resolver respects only allowlist and preserves order', function () {
    $server = fakeMcpServer('test', [
        ['name' => 'alpha', 'description' => '', 'inputSchema' => ['type' => 'object']],
        ['name' => 'beta', 'description' => '', 'inputSchema' => ['type' => 'object']],
        ['name' => 'gamma', 'description' => '', 'inputSchema' => ['type' => 'object']],
    ]);

    $manager = Mockery::mock(McpManager::class);
    $manager->shouldReceive('server')->with('test')->andReturn($server);

    $tools = (new McpToolResolver($manager))->tools([
        Mcp::server('test')->only(['gamma', 'alpha']),
    ]);

    expect($tools)->toHaveCount(2)
        ->and($tools[0]->mcpName())->toBe('gamma')
        ->and($tools[1]->mcpName())->toBe('alpha');
});

test('resolver throws when an allowlisted tool is missing from the server', function () {
    $server = fakeMcpServer('test', [
        ['name' => 'alpha', 'description' => '', 'inputSchema' => ['type' => 'object']],
    ]);

    $manager = Mockery::mock(McpManager::class);
    $manager->shouldReceive('server')->with('test')->andReturn($server);

    expect(fn () => (new McpToolResolver($manager))->tools([
        Mcp::server('test')->only(['alpha', 'missing']),
    ]))->toThrow(InvalidArgumentException::class, 'missing');
});

test('resolver assigns provider-safe aliases to each adapter', function () {
    $server = fakeMcpServer('files', [
        ['name' => 'read.file', 'description' => '', 'inputSchema' => ['type' => 'object']],
    ]);

    $manager = Mockery::mock(McpManager::class);
    $manager->shouldReceive('server')->with('files')->andReturn($server);

    $tools = (new McpToolResolver($manager))->tools(['files']);

    expect($tools[0]->name())->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and($tools[0]->name())->not->toContain('.')
        ->and(strlen($tools[0]->name()))->toBeLessThanOrEqual(64);
});
