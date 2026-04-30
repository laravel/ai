<?php

use Laravel\Ai\Mcp;
use Laravel\Ai\Mcp\McpManager;
use Laravel\Ai\Mcp\McpClient;
use Laravel\Ai\Mcp\ServerReference;

test('the Mcp facade builds a server reference', function () {
    $reference = Mcp::server('filesystem');

    expect($reference)->toBeInstanceOf(ServerReference::class)
        ->and($reference->name)->toBe('filesystem')
        ->and($reference->only)->toBeNull();
});

test('the Mcp facade builds a server reference with a tool allowlist', function () {
    $reference = Mcp::server('filesystem')->only(['read_file', 'write_file']);

    expect($reference)->toBeInstanceOf(ServerReference::class)
        ->and($reference->name)->toBe('filesystem')
        ->and($reference->only)->toBe(['read_file', 'write_file']);
});

test('the Mcp facade forwards manager calls via the bound manager', function () {
    config(['ai.mcp.servers.custom' => ['transport' => 'memory']]);

    $stub = Mockery::mock(McpClient::class);

    Mcp::extend('memory', fn () => $stub);

    expect(app(McpManager::class)->instance('custom'))->toBe($stub);
});
