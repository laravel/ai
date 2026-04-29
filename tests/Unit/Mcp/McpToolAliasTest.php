<?php

use Laravel\Ai\Mcp\McpToolAlias;

test('alias is provider safe and within length limits', function () {
    $alias = McpToolAlias::make('filesystem', 'read_file');

    expect($alias)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(strlen($alias))->toBeLessThanOrEqual(64)
        ->and($alias)->toContain('filesystem')
        ->and($alias)->toContain('read_file');
});

test('alias is deterministic for the same server and tool', function () {
    expect(McpToolAlias::make('github', 'search_repositories'))
        ->toBe(McpToolAlias::make('github', 'search_repositories'));
});

test('alias differs across distinct server tool pairs', function () {
    $a = McpToolAlias::make('alpha', 'do_thing');
    $b = McpToolAlias::make('alpha', 'do_other_thing');
    $c = McpToolAlias::make('beta', 'do_thing');

    expect($a)->not->toBe($b)
        ->and($a)->not->toBe($c)
        ->and($b)->not->toBe($c);
});

test('alias strips dots and other illegal characters', function () {
    $alias = McpToolAlias::make('mcp.fs', 'admin.tools.list');

    expect($alias)->not->toContain('.')
        ->and($alias)->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('alias is capped to satisfy provider tool name limits', function () {
    $longServer = str_repeat('server', 20);
    $longTool = str_repeat('tool', 20);

    $alias = McpToolAlias::make($longServer, $longTool);

    expect(strlen($alias))->toBeLessThanOrEqual(64);
});

test('alias falls back when server and tool sanitize to nothing', function () {
    $alias = McpToolAlias::make('!!!', '@@@');

    expect($alias)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(strlen($alias))->toBeLessThanOrEqual(64);
});
