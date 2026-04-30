<?php

use Laravel\Ai\Mcp\Transports\StdioTransport;

test('stdio transport requires an id when sending requests', function () {
    $transport = new StdioTransport([PHP_BINARY]);

    expect(fn () => $transport->send([
        'jsonrpc' => '2.0',
        'method' => 'tools/list',
    ]))->toThrow(InvalidArgumentException::class, 'must include an [id]');
});

test('stdio transport rejects an id when sending notifications', function () {
    $transport = new StdioTransport([PHP_BINARY]);

    expect(fn () => $transport->notify([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'notifications/initialized',
    ]))->toThrow(InvalidArgumentException::class, 'must not include an [id]');
});
