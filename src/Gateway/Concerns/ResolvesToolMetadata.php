<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\HasRawToolSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

trait ResolvesToolMetadata
{
    /**
     * Determine if a tool provides its own raw JSON Schema.
     */
    protected function toolHasRawSchema(Tool $tool): bool
    {
        return $tool instanceof HasRawToolSchema;
    }

    /**
     * Get a JSON Schema array for a tool.
     */
    protected function toolSchemaArray(Tool $tool): array
    {
        if ($tool instanceof HasRawToolSchema) {
            return $this->normalizeRawToolSchema($tool->rawSchema());
        }

        $schema = $tool->schema(new JsonSchemaTypeFactory);

        return filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];
    }

    /**
     * Normalize an MCP JSON Schema object for provider tool declarations.
     */
    protected function normalizeRawToolSchema(array $schema): array
    {
        $schema['type'] ??= 'object';

        if (! array_key_exists('properties', $schema)) {
            $schema['properties'] = [];
        }

        if (! array_key_exists('required', $schema)) {
            $schema['required'] = [];
        }

        return $schema;
    }
}
