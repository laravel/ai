<?php

use Laravel\Ai\Contracts\HasMcpServers;
use Laravel\Ai\Mcp\Mcp;
use Laravel\Ai\Mcp\ServerReference;

use function Laravel\Ai\agent;

test('anonymous agents may declare mcp servers', function () {
    $agent = agent(
        instructions: 'You can use external tools.',
        mcpServers: ['filesystem', Mcp::server('github')->only(['search_repositories'])],
    );

    expect($agent)->toBeInstanceOf(HasMcpServers::class)
        ->and($agent->mcpServers())->toHaveCount(2)
        ->and($agent->mcpServers()[0])->toBe('filesystem')
        ->and($agent->mcpServers()[1])->toBeInstanceOf(ServerReference::class)
        ->and($agent->mcpServers()[1]->name)->toBe('github')
        ->and($agent->mcpServers()[1]->only)->toBe(['search_repositories']);
});

test('structured anonymous agents may declare mcp servers', function () {
    $agent = agent(
        instructions: 'Return JSON.',
        schema: fn ($schema) => ['answer' => $schema->string()],
        mcpServers: [Mcp::server('filesystem')->only(['read_file'])],
    );

    expect($agent)->toBeInstanceOf(HasMcpServers::class)
        ->and($agent->mcpServers())->toHaveCount(1)
        ->and($agent->mcpServers()[0])->toBeInstanceOf(ServerReference::class)
        ->and($agent->mcpServers()[0]->name)->toBe('filesystem')
        ->and($agent->mcpServers()[0]->only)->toBe(['read_file']);
});

test('mcp server references are immutable when limiting tools', function () {
    $server = Mcp::server('github');
    $limited = $server->only(['search_repositories', 'create_issue']);

    expect($server)->not->toBe($limited)
        ->and($server->name)->toBe('github')
        ->and($server->only)->toBeNull()
        ->and($limited->name)->toBe('github')
        ->and($limited->only)->toBe(['search_repositories', 'create_issue']);
});
