<?php

namespace Laravel\Ai\Gateway\Zai;

use stdClass;
use Laravel\Ai\Contracts\Tool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

class ZaiTool
{
    /**
     * Convert a Laravel Tool to Z.AI format.
     * Z.AI expects: {type: "function", function: {name, description, parameters}}
     */
    public static function toZaiFormat(Tool $tool): array
    {
        $toolName = method_exists($tool, 'name')
            ? $tool->name()
            : class_basename($tool);

        return [
            'type' => 'function',
            'function' => [
                'name' => $toolName,
                'description' => (string) $tool->description(),
                'parameters' => self::formatParameters($tool),
            ],
        ];
    }

    /**
     * Format tool parameters from Laravel schema.
     * Converts Laravel schema array to Z.AI JSON Schema format.
     */
    protected static function formatParameters(Tool $tool): array
    {
        $schemaFactory = new JsonSchemaTypeFactory;
        $schemaArray = $tool->schema($schemaFactory);

        if (empty($schemaArray)) {
            return [
                'type' => 'object',
                'properties' => new stdClass,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $schemaArray,
        ];
    }

    /**
     * Parse Z.AI tool call response to Laravel ToolCall data.
     */
    public static function toLaravelToolCall(array $toolCall): array
    {
        return [
            'id' => $toolCall['id'] ?? '',
            'name' => $toolCall['function']['name'] ?? '',
            'arguments' => self::parseArguments($toolCall['function']['arguments'] ?? []),
        ];
    }

    /**
     * Parse tool arguments (handle JSON string or array).
     * Z.AI may send arguments as concatenated JSON strings in streaming.
     */
    protected static function parseArguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($arguments) ? $arguments : [];
    }
}
