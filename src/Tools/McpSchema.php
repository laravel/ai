<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\BooleanType;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;

class McpSchema
{
    public function __construct(protected JsonSchema $schema) {}

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, Type>
     */
    public function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return [];
        }

        $required = $this->stringList($schema['required'] ?? []);

        $result = [];

        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                continue;
            }

            $result[$name] = $this->type($property, in_array($name, $required, true));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function type(array $schema, bool $required = false): Type
    {
        [$schema, $nullable] = $this->normalizeNullableSchema($schema);

        $type = match ($this->typeName($schema)) {
            'array' => $this->array($schema),
            'boolean' => $this->schema->boolean(),
            'integer' => $this->integer($schema),
            'number' => $this->number($schema),
            'object' => $this->object($schema),
            default => $this->string($schema),
        };

        return $this->applyCommon($type, $schema, $required, $nullable);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function array(array $schema): ArrayType
    {
        $type = $this->schema->array();

        if (isset($schema['items']) && is_array($schema['items']) && ! array_is_list($schema['items'])) {
            $type->items($this->type($schema['items']));
        }

        if (isset($schema['minItems']) && is_int($schema['minItems'])) {
            $type->min($schema['minItems']);
        }

        if (isset($schema['maxItems']) && is_int($schema['maxItems'])) {
            $type->max($schema['maxItems']);
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $type->unique();
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function integer(array $schema): IntegerType
    {
        $type = $this->schema->integer();

        if (isset($schema['minimum']) && is_int($schema['minimum'])) {
            $type->min($schema['minimum']);
        }

        if (isset($schema['maximum']) && is_int($schema['maximum'])) {
            $type->max($schema['maximum']);
        }

        if (isset($schema['multipleOf']) && is_int($schema['multipleOf'])) {
            $type->multipleOf($schema['multipleOf']);
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function number(array $schema): NumberType
    {
        $type = $this->schema->number();

        if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))) {
            $type->min($schema['minimum']);
        }

        if (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']))) {
            $type->max($schema['maximum']);
        }

        if (isset($schema['multipleOf']) && (is_int($schema['multipleOf']) || is_float($schema['multipleOf']))) {
            $type->multipleOf($schema['multipleOf']);
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function object(array $schema): ObjectType
    {
        $properties = $schema['properties'] ?? [];
        $required = $this->stringList($schema['required'] ?? []);

        $mapped = [];

        if (is_array($properties)) {
            foreach ($properties as $name => $property) {
                if (! is_string($name) || ! is_array($property)) {
                    continue;
                }

                $mapped[$name] = $this->type($property, in_array($name, $required, true));
            }
        }

        return $this->schema->object($mapped);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function string(array $schema): StringType
    {
        $type = $this->schema->string();

        if (isset($schema['minLength']) && is_int($schema['minLength'])) {
            $type->min($schema['minLength']);
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength'])) {
            $type->max($schema['maxLength']);
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $type->pattern($schema['pattern']);
        }

        if (isset($schema['format']) && is_string($schema['format'])) {
            $type->format($schema['format']);
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function applyCommon(Type $type, array $schema, bool $required, bool $nullable): Type
    {
        if ($required) {
            $type->required();
        }

        if ($nullable) {
            $type->nullable();
        }

        if (isset($schema['title']) && is_string($schema['title'])) {
            $type->title($schema['title']);
        }

        if (isset($schema['description']) && is_string($schema['description'])) {
            $type->description($schema['description']);
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $type->enum(array_values($schema['enum']));
        }

        if (array_key_exists('default', $schema)) {
            $this->applyDefault($type, $schema['default']);
        }

        return $type;
    }

    protected function applyDefault(Type $type, mixed $default): void
    {
        match (true) {
            $type instanceof ArrayType && is_array($default) => $type->default($default),
            $type instanceof BooleanType && is_bool($default) => $type->default($default),
            $type instanceof IntegerType && is_int($default) => $type->default($default),
            $type instanceof NumberType && (is_int($default) || is_float($default)) => $type->default($default),
            $type instanceof ObjectType && is_array($default) => $type->default($default),
            $type instanceof StringType && is_string($default) => $type->default($default),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{array<string, mixed>, bool}
     */
    protected function normalizeNullableSchema(array $schema): array
    {
        $nullable = false;
        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $nullable = in_array('null', $type, true);
            $types = array_values(array_filter($type, fn ($value) => is_string($value) && $value !== 'null'));

            if ($types !== []) {
                $schema['type'] = $types[0];
            }
        } elseif ($type === 'null') {
            $nullable = true;
            unset($schema['type']);
        }

        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                [$schema, $compositionNullable] = $this->normalizeComposition($schema, $key);
                $nullable = $nullable || $compositionNullable;
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && in_array(null, $schema['enum'], true)) {
            $nullable = true;
        }

        return [$schema, $nullable];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{array<string, mixed>, bool}
     */
    protected function normalizeComposition(array $schema, string $key): array
    {
        $schemas = $schema[$key];
        $nullable = false;

        if (! is_array($schemas)) {
            return [$schema, false];
        }

        foreach ($schemas as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (($candidate['type'] ?? null) === 'null') {
                $nullable = true;

                continue;
            }

            unset($schema[$key]);

            return [array_replace($candidate, $schema), $nullable];
        }

        unset($schema[$key]);

        return [$schema, $nullable];
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function typeName(array $schema): string
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return $type;
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            return 'object';
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            return 'array';
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            foreach ($schema['enum'] as $value) {
                if ($value === null) {
                    continue;
                }

                return match (true) {
                    is_bool($value) => 'boolean',
                    is_int($value) => 'integer',
                    is_float($value) => 'number',
                    is_array($value) => array_is_list($value) ? 'array' : 'object',
                    default => 'string',
                };
            }
        }

        return 'string';
    }

    /**
     * @return array<int, string>
     */
    protected function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
