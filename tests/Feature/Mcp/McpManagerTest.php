<?php

use Laravel\Ai\Contracts\Mcp\McpTransport;
use Laravel\Ai\Mcp\McpManager;
use Laravel\Ai\Mcp\McpServer;

test('the manager resolves stdio servers from config', function () {
    config(['ai.mcp.servers.test' => [
        'transport' => 'stdio',
        'command' => [PHP_BINARY, __DIR__.'/../../Fixtures/Mcp/stdio-server.php'],
    ]]);

    $server = (new McpManager(app()))->instance('test');

    expect($server)->toBeInstanceOf(McpServer::class)
        ->and($server->name())->toBe('test');
});

test('the manager allows third-party transports via extend', function () {
    $transport = Mockery::mock(McpTransport::class);

    config(['ai.mcp.servers.custom' => ['transport' => 'memory']]);

    $manager = new McpManager(app());

    $manager->extend('memory', fn ($app, array $config) => new McpServer($config['name'], $transport));

    expect($manager->instance('custom'))->toBeInstanceOf(McpServer::class)
        ->and($manager->instance('custom')->name())->toBe('custom')
        ->and($manager->instance('custom'))->toBe($manager->instance('custom'));
});

test('the manager throws when a server is not configured', function () {
    expect(fn () => (new McpManager(app()))->instance('missing'))
        ->toThrow(InvalidArgumentException::class, 'MCP server [missing] is not configured.');
});

test('the manager throws when a configured transport is unsupported', function () {
    config(['ai.mcp.servers.broken' => ['transport' => 'carrier-pigeon']]);

    expect(fn () => (new McpManager(app()))->instance('broken'))
        ->toThrow(InvalidArgumentException::class, 'carrier-pigeon');
});

test('purge disconnects and forgets the named server', function () {
    $server = Mockery::mock(McpServer::class);
    $server->shouldReceive('disconnect')->once();

    config(['ai.mcp.servers.custom' => ['transport' => 'memory']]);

    $manager = new McpManager(app());
    $manager->extend('memory', fn () => $server);

    $manager->instance('custom');
    $manager->purge('custom');
});

test('disconnectAll disconnects every cached server', function () {
    $alpha = Mockery::mock(McpServer::class);
    $alpha->shouldReceive('disconnect')->once();

    $beta = Mockery::mock(McpServer::class);
    $beta->shouldReceive('disconnect')->once();

    config([
        'ai.mcp.servers.alpha' => ['transport' => 'memory'],
        'ai.mcp.servers.beta' => ['transport' => 'memory'],
    ]);

    $manager = new McpManager(app());
    $manager->extend('memory', fn ($app, array $config) => $config['name'] === 'alpha' ? $alpha : $beta);

    $manager->instance('alpha');
    $manager->instance('beta');
    $manager->disconnectAll();
});
