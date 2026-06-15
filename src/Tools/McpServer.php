<?php

namespace Laravel\Ai\Tools;

use ArrayIterator;
use Illuminate\Container\Container;
use IteratorAggregate;
use Traversable;

class McpServer implements IteratorAggregate
{
    /**
     * The MCP server class name.
     */
    protected const MCP_SERVER = 'Laravel\\Mcp\\Server';

    /**
     * The fake transporter class name used for in-process tool discovery.
     */
    protected const FAKE_TRANSPORT = 'Laravel\\Mcp\\Server\\Transport\\FakeTransporter';

    /**
     * Create a new MCP server wrapper instance.
     */
    public function __construct(protected string|object $server) {}

    /**
     * Create a new MCP server wrapper for the given server class or instance.
     */
    public static function make(string|object $server): static
    {
        return new static($server);
    }

    /**
     * Determine whether the given value is an MCP server definition.
     */
    public static function supports(mixed $value): bool
    {
        if (! class_exists(self::MCP_SERVER)) {
            return false;
        }

        if (is_string($value)) {
            return class_exists($value) && is_a($value, self::MCP_SERVER, true);
        }

        return is_object($value) && is_a($value, self::MCP_SERVER);
    }

    /**
     * Get the individual MCP server tools wrapped for the AI SDK.
     *
     * @return array<int, McpServerTool>
     */
    public function tools(): array
    {
        $instance = $this->resolveInstance();

        if (! is_object($instance) || ! method_exists($instance, 'createContext')) {
            return [];
        }

        return $instance
            ->createContext()
            ->tools()
            ->map(fn (object $tool) => new McpServerTool($tool))
            ->values()
            ->all();
    }

    /**
     * Allow spreading the wrapper directly to yield the wrapped tools.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tools());
    }

    /**
     * Resolve a server instance, using a fake transport when constructing from a class string.
     */
    protected function resolveInstance(): object
    {
        if (is_object($this->server)) {
            return $this->server;
        }

        $container = Container::getInstance();

        $transportClass = self::FAKE_TRANSPORT;
        $transport = class_exists($transportClass) ? new $transportClass : null;

        return $container->make($this->server, ['transport' => $transport]);
    }
}
