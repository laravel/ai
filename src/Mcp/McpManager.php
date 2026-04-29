<?php

namespace Laravel\Ai\Mcp;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Laravel\Ai\Mcp\Transports\McpTransport;
use Laravel\Ai\Mcp\Transports\StdioTransport;

class McpManager
{
    /**
     * The cached MCP server instances.
     *
     * @var array<string, McpServer>
     */
    protected array $servers = [];

    public function __construct(protected Application $app) {}

    /**
     * Get or create a configured MCP server by name.
     */
    public function server(string $name): McpServer
    {
        if (isset($this->servers[$name])) {
            return $this->servers[$name];
        }

        $config = $this->app['config']->get("ai.mcp.servers.{$name}");

        if (is_null($config)) {
            throw new InvalidArgumentException("MCP server [{$name}] is not configured.");
        }

        return $this->servers[$name] = new McpServer($name, $this->createTransport($config));
    }

    /**
     * Disconnect all active MCP server connections.
     */
    public function disconnectAll(): void
    {
        foreach ($this->servers as $server) {
            $server->disconnect();
        }

        $this->servers = [];
    }

    /**
     * Create a transport instance from configuration.
     */
    protected function createTransport(array $config): McpTransport
    {
        $transport = $config['transport'] ?? null;

        return match ($transport) {
            'stdio' => new StdioTransport(
                command: $config['command'] ?? throw new InvalidArgumentException('MCP stdio servers require a [command] configuration value.'),
                env: $config['env'] ?? [],
                timeout: $config['timeout'] ?? 30,
            ),
            default => throw new InvalidArgumentException("Unsupported MCP transport [{$transport}]."),
        };
    }
}
