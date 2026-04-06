<?php

namespace Laravel\Ai;

use Illuminate\JsonSchema\Types\ObjectType;
use Prism\Prism\Contracts\HasSchemaType;

class ObjectSchema extends Schema implements HasSchemaType
{
    /**
     * Create a new output schema.
     */
    public function __construct(
        array $schema,
        string $name = 'schema_definition',
        bool $strict = true
    ) {
        parent::__construct(
            schema: (new ObjectType($schema))->withoutAdditionalProperties(),
            name: $name,
            strict: $strict
        );
    }

    /**
     * Get the array representation of the schema with additional properties disabled on all nested objects.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return static::disableAdditionalProperties(parent::toSchema());
    }

    /**
     * Recursively set "additionalProperties" to false on all object nodes.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function disableAdditionalProperties(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'object' || (is_array($type) && in_array('object', $type))) {
            $schema['additionalProperties'] = false;

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = static::disableAdditionalProperties($property);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = static::disableAdditionalProperties($schema['items']);
        }

        return $schema;
    }

    /**
     * Get the Prism-compatible schema type.
     */
    public function schemaType(): string
    {
        return 'object';
    }
}
