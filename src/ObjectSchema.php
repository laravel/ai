<?php

namespace Laravel\Ai;

use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
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
        $rootType = (new ObjectType($schema))->withoutAdditionalProperties();

        static::disableAdditionalPropertiesRecursively($schema);

        parent::__construct(
            schema: $rootType,
            name: $name,
            strict: $strict
        );
    }

    /**
     * Recursively disable additional properties on all nested object types.
     *
     * @param  array<string, Type>  $properties
     */
    protected static function disableAdditionalPropertiesRecursively(array $properties): void
    {
        foreach ($properties as $property) {
            if ($property instanceof ObjectType) {
                $property->withoutAdditionalProperties();

                $nested = (fn () => $this->properties)->call($property);

                static::disableAdditionalPropertiesRecursively($nested);
            } elseif ($property instanceof ArrayType) {
                $items = (fn () => $this->items)->call($property);

                if ($items instanceof ObjectType) {
                    $items->withoutAdditionalProperties();

                    $nested = (fn () => $this->properties)->call($items);

                    static::disableAdditionalPropertiesRecursively($nested);
                } elseif ($items instanceof ArrayType) {
                    static::disableAdditionalPropertiesRecursively(['items' => $items]);
                }
            }
        }
    }

    /**
     * Get the Prism-compatible schema type.
     */
    public function schemaType(): string
    {
        return 'object';
    }
}
