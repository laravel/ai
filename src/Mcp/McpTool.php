<?php

namespace Laravel\Ai\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasRawToolSchema;
use Laravel\Ai\Contracts\NamedTool;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request;
use Stringable;

class McpTool implements HasRawToolSchema, NamedTool, ToolContract
{
    public function __construct(
        public readonly McpServer $server,
        public readonly Tool $tool,
        protected string $alias,
    ) {}

    /**
     * Get the provider-facing tool name.
     */
    public function name(): string
    {
        return $this->alias;
    }

    /**
     * Get the original MCP tool name.
     */
    public function mcpName(): string
    {
        return $this->tool->name;
    }

    /**
     * Get the tool description.
     */
    public function description(): Stringable|string
    {
        return $this->tool->description;
    }

    /**
     * Execute the MCP tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->server
            ->callTool($this->tool->name, $request->all())
            ->toText();
    }

    /**
     * MCP tools provide raw JSON Schema instead of Laravel schema types.
     */
    public function rawSchema(): array
    {
        return $this->tool->inputSchema;
    }

    /**
     * Required by the Tool contract; rawSchema is used for provider mapping.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
