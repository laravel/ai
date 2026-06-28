<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Schema\SchemaNormalizer;
use Laravel\Ai\Tools\Concerns\NormalizesMcpResult;
use Throwable;

class McpTool implements Tool
{
    use NormalizesMcpResult;

    /**
     * The MCP client tool primitive class name.
     */
    protected const MCP_CLIENT_TOOL = 'Laravel\\Mcp\\Client\\Primitives\\Tool';

    /**
     * The prefix applied to MCP client tool names.
     */
    protected const NAME_PREFIX = 'mcp_tools_';

    /**
     * Create a new MCP client tool wrapper instance.
     */
    public function __construct(protected object $tool) {}

    /**
     * Determine whether the given value is an MCP client tool primitive.
     */
    public static function supports(mixed $tool): bool
    {
        return is_object($tool) && is_a($tool, self::MCP_CLIENT_TOOL);
    }

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return self::NAME_PREFIX.$this->tool->name;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return $this->tool->description ?? $this->tool->title ?? $this->tool->name;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return $this->convertResult($this->tool->call($request->all()));
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $input = $this->tool->inputSchema ?? [];

        if (! is_array($input) || $input === []) {
            return [];
        }

        try {
            $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize($input));
        } catch (Throwable) {
            return [];
        }

        return $type instanceof ObjectType
            ? (fn (): array => $this->properties)->call($type)
            : [];
    }

    /**
     * Convert an MCP tool result into tool output.
     */
    protected function convertResult(object $result): string
    {
        if (($result->isError ?? false) === true) {
            return $this->errorResult($result);
        }

        if (($result->structuredContent ?? null) !== null) {
            return $this->json($result->structuredContent);
        }

        return $this->text($result);
    }

    /**
     * Convert an MCP error result into tool output.
     */
    protected function errorResult(object $result): string
    {
        $text = $this->text($result);

        if ($text === '' && ($result->structuredContent ?? null) !== null) {
            $text = $this->json($result->structuredContent);
        }

        return $this->errorMessage($text);
    }

    /**
     * Extract the text content from an MCP tool result.
     */
    protected function text(object $result): string
    {
        return is_callable([$result, 'text'])
            ? $result->text()
            : (string) $result;
    }
}
