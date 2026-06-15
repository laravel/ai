<?php

use Illuminate\Container\Container;
use Laravel\Ai\Tools\McpServer;
use Laravel\Ai\Tools\McpServerTool;
use Tests\Fixtures\Mcp\FakeMcpServer;

beforeEach(function () {
    Container::setInstance(new Container);
});

test('it detects mcp server classes and instances', function () {
    expect([
        McpServer::supports(FakeMcpServer::class),
        McpServer::supports(new FakeMcpServer),
        McpServer::supports(new stdClass),
        McpServer::supports('NonExistentClass'),
    ])->sequence(
        fn ($s) => $s->toBeTrue(),
        fn ($s) => $s->toBeTrue(),
        fn ($s) => $s->toBeFalse(),
        fn ($s) => $s->toBeFalse(),
    );
});

test('it returns wrapped tools from a server class string', function () {
    $server = new McpServer(FakeMcpServer::class);

    $tools = $server->tools();

    expect($tools)->toHaveCount(2);
    expect($tools[0])->toBeInstanceOf(McpServerTool::class);
    expect($tools[0]->name())->toBe('fake-mcp-server-tool');
    expect($tools[1]->name())->toBe('fake-structured-mcp-server-tool');
});

test('it returns wrapped tools from a server instance', function () {
    $instance = new FakeMcpServer;
    $server = new McpServer($instance);

    $tools = $server->tools();

    expect($tools)->toHaveCount(2);
    expect($tools[0]->name())->toBe('fake-mcp-server-tool');
});

test('it can be iterated to yield wrapped tools', function () {
    $server = McpServer::make(FakeMcpServer::class);

    $collected = [];
    foreach ($server as $tool) {
        $collected[] = $tool;
    }

    expect($collected)->toHaveCount(2)
        ->and($collected[0])->toBeInstanceOf(McpServerTool::class);
});

test('make is a static factory', function () {
    $server = McpServer::make(FakeMcpServer::class);

    expect($server)->toBeInstanceOf(McpServer::class);
    expect($server->tools())->toHaveCount(2);
});
