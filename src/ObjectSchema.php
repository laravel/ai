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
     * Get the Prism-compatible schema type.
     */
    public function schemaType(): string
    {
        return 'object';
    }
}
