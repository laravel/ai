<?php

namespace Laravel\Ai\Mcp;

use Laravel\Ai\Exceptions\McpException;
use Laravel\Ai\Mcp\Protocol\JsonRpc;
use Laravel\Ai\Contracts\Mcp\McpTransport;

class McpServer
{
    public const ProtocolVersion = '2025-11-25';

    /**
     * Whether the MCP connection has been initialized.
     */
    protected bool $initialized = false;

    /**
     * The cached tool definitions from this server.
     *
     * @var array<Tool>|null
     */
    protected ?array $tools = null;

    /**
     * The server's negotiated protocol version.
     */
    protected ?string $protocolVersion = null;

    /**
     * The server's capabilities.
     */
    protected array $capabilities = [];

    /**
     * Optional server instructions.
     */
    protected ?string $instructions = null;

    public function __construct(
        protected string $name,
        protected McpTransport $transport,
    ) {}

    /**
     * Get the configured server name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Initialize the MCP connection.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->transport->open();

        $response = $this->transport->send(JsonRpc::request('initialize', [
            'protocolVersion' => self::ProtocolVersion,
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'laravel-ai',
                'title' => 'Laravel AI',
                'version' => '0.x',
                'description' => 'The official AI SDK for Laravel.',
            ],
        ]));

        $result = JsonRpc::result($response) ?? [];

        $this->protocolVersion = $result['protocolVersion'] ?? self::ProtocolVersion;

        if ($this->protocolVersion !== self::ProtocolVersion) {
            $this->transport->close();

            throw McpException::unsupportedProtocolVersion($this->name, $this->protocolVersion);
        }

        $this->capabilities = $result['capabilities'] ?? [];
        $this->instructions = $result['instructions'] ?? null;

        $this->transport->notify(JsonRpc::notification('notifications/initialized'));

        $this->initialized = true;
    }

    /**
     * List the server's available tools.
     *
     * @return array<Tool>
     */
    public function tools(): array
    {
        $this->initialize();

        if (! array_key_exists('tools', $this->capabilities)) {
            return [];
        }

        if (! is_null($this->tools)) {
            return $this->tools;
        }

        $tools = [];
        $cursor = null;

        do {
            $params = [];

            if (! is_null($cursor)) {
                $params['cursor'] = $cursor;
            }

            $response = $this->transport->send(JsonRpc::request('tools/list', $params));
            $result = JsonRpc::result($response) ?? [];

            foreach ($result['tools'] ?? [] as $tool) {
                $tools[] = Tool::fromArray($tool);
            }

            $cursor = $result['nextCursor'] ?? null;
        } while (! is_null($cursor));

        return $this->tools = $tools;
    }

    /**
     * Call a tool on the server.
     */
    public function callTool(string $name, array $arguments = []): ToolResult
    {
        $this->initialize();

        $response = $this->transport->send(JsonRpc::request('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]));

        return ToolResult::fromArray(JsonRpc::result($response) ?? []);
    }

    /**
     * Forget cached tool definitions.
     */
    public function refreshTools(): void
    {
        $this->tools = null;
    }

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void
    {
        $this->transport->close();

        $this->initialized = false;
        $this->tools = null;
        $this->protocolVersion = null;
        $this->capabilities = [];
        $this->instructions = null;
    }

    /**
     * Get server capabilities.
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Get server instructions.
     */
    public function instructions(): ?string
    {
        return $this->instructions;
    }
}
