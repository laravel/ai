<?php

namespace Laravel\Ai\Mcp;

use Illuminate\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Laravel\Ai\Mcp\Transports\StdioTransport;

class McpManager extends MultipleInstanceManager
{
    /**
     * The configuration key that selects the transport implementation.
     *
     * @var string
     */
    protected $driverKey = 'transport';

    /**
     * Get a configured MCP server by name.
     */
    public function server(?string $name = null): McpServer
    {
        return $this->instance($name);
    }

    /**
     * Disconnect all active MCP server connections.
     */
    public function disconnectAll(): void
    {
        foreach ($this->instances as $server) {
            $server->disconnect();
        }

        $this->instances = [];
    }

    /**
     * Disconnect and forget the given MCP server instance.
     *
     * @param  string|null  $name
     */
    public function purge($name = null): void
    {
        $name ??= $this->getDefaultInstance();

        if (isset($this->instances[$name])) {
            $this->instances[$name]->disconnect();
        }

        unset($this->instances[$name]);
    }

    /**
     * Create an MCP server backed by the stdio transport.
     */
    public function createStdioTransport(array $config): McpServer
    {
        return new McpServer($config['name'], new StdioTransport(
            command: $config['command'] ?? throw new InvalidArgumentException('MCP stdio servers require a [command] configuration value.'),
            env: $config['env'] ?? [],
            timeout: $config['timeout'] ?? 30,
        ));
    }

    /**
     * Get the default instance name.
     */
    public function getDefaultInstance(): ?string
    {
        return $this->app['config']['ai.mcp.default'];
    }

    /**
     * Set the default instance name.
     *
     * @param  string  $name
     */
    public function setDefaultInstance($name): void
    {
        $this->app['config']['ai.mcp.default'] = $name;
    }

    /**
     * Get the instance specific configuration.
     *
     * @param  string  $name
     */
    public function getInstanceConfig($name): array
    {
        $config = $this->app['config']->get('ai.mcp.servers.'.$name);

        if (is_null($config)) {
            throw new InvalidArgumentException("MCP server [{$name}] is not configured.");
        }

        $config['name'] = $name;

        return $config;
    }
}
