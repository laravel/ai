<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;

class McpTool implements Tool
{
    protected const MCP_CLIENT_TOOL = 'Laravel\\Mcp\\Client\\Primitives\\Tool';

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
        return $this->tool->name;
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
        return (new McpSchema($schema))->properties($this->tool->inputSchema ?? []);
    }

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

    protected function errorResult(object $result): string
    {
        $text = $this->text($result);

        if ($text === '' && ($result->structuredContent ?? null) !== null) {
            $text = $this->json($result->structuredContent);
        }

        return $text === ''
            ? 'MCP tool error.'
            : 'MCP tool error: '.$text;
    }

    protected function text(object $result): string
    {
        return is_callable([$result, 'text'])
            ? $result->text()
            : (string) $result;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    protected function json(array $content): string
    {
        return json_encode($content, JSON_UNESCAPED_UNICODE) ?: '';
    }
}
