<?php

namespace Laravel\Ai\Schema;

class SchemaNormalizer
{
    /**
     * Type-specific constraint keywords the deserializer rejects on a multi-type union.
     */
    private const TYPE_KEYWORDS = [
        'minLength', 'maxLength', 'pattern', 'format',
        'minimum', 'maximum', 'multipleOf',
        'items', 'minItems', 'maxItems', 'uniqueItems',
        'properties', 'required', 'additionalProperties',
    ];

    /**
     * Keywords the Laravel JSON Schema deserializer cannot represent and that
     * are either ignored or cause it to throw. Drop them up front.
     */
    private const UNSUPPORTED = [
        '$schema', '$id', '$anchor', '$comment', 'not', 'if', 'then', 'else',
        'patternProperties', 'dependentSchemas', 'dependentRequired', 'unevaluatedProperties',
        'contains', 'minContains', 'maxContains', 'prefixItems', 'examples', 'deprecated',
        'readOnly', 'writeOnly', 'minProperties', 'maxProperties', 'exclusiveMinimum', 'exclusiveMaximum',
    ];

    /**
     * Rewrite a raw JSON Schema into the subset Illuminate\JsonSchema can deserialize.
     *
     * Lossy by design: constructs the typed schema cannot represent (rich unions,
     * allOf, tuples) are collapsed or dropped rather than rejected.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        $defs = match (true) {
            is_array($schema['$defs'] ?? null) => $schema['$defs'],
            is_array($schema['definitions'] ?? null) => $schema['definitions'],
            default => [],
        };

        return (new self)->node($schema, $defs, []);
    }

    /**
     * Normalize a single schema node.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $defs
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function node(array $schema, array $defs, array $seen): array
    {
        [$schema, $seen] = $this->inlineRefs($schema, $defs, $seen);

        $schema = $this->mergeAllOf($schema, $defs, $seen);
        $schema = $this->collapseUnions($schema, $defs, $seen);
        $schema = $this->collapseMultiType($schema);

        // Strip after merging so keywords pulled in from allOf branches are dropped too.
        foreach (self::UNSUPPORTED as $keyword) {
            unset($schema[$keyword]);
        }

        unset($schema['$defs'], $schema['definitions']);

        if (array_key_exists('default', $schema) && $schema['default'] === null) {
            unset($schema['default']);
        }

        if (($schema['additionalProperties'] ?? false) !== false) {
            unset($schema['additionalProperties']);
        }

        if (is_array($schema['properties'] ?? null)) {
            $properties = [];

            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition)) {
                    $properties[$key] = $this->node($definition, $defs, $seen);
                }
            }

            $schema['properties'] = $properties;

            if (is_array($schema['required'] ?? null)) {
                $schema['required'] = array_values(array_filter(
                    $schema['required'],
                    fn ($name) => is_string($name) && array_key_exists($name, $properties),
                ));
            }
        }

        if (isset($schema['items'])) {
            if (is_array($schema['items']) && ! array_is_list($schema['items'])) {
                $schema['items'] = $this->node($schema['items'], $defs, $seen);
            } else {
                unset($schema['items']);
            }
        }

        return $this->ensureType($schema);
    }

    /**
     * Inline local "$ref" pointers (cycle-safe); drop remote or unresolvable ones.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $defs
     * @param  array<string, true>  $seen
     * @return array{0: array<string, mixed>, 1: array<string, true>}
     */
    private function inlineRefs(array $schema, array $defs, array $seen): array
    {
        while (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];

            unset($schema['$ref']);

            $key = match (true) {
                str_starts_with($ref, '#/$defs/') => substr($ref, 8),
                str_starts_with($ref, '#/definitions/') => substr($ref, 14),
                default => null,
            };

            if ($key === null || ! is_array($defs[$key] ?? null) || isset($seen[$key])) {
                break;
            }

            $seen[$key] = true;
            $schema = array_merge($defs[$key], $schema);
        }

        return [$schema, $seen];
    }

    /**
     * Merge "allOf" branches into the node; the deserializer has no intersection type.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $defs
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function mergeAllOf(array $schema, array $defs, array $seen): array
    {
        // Loop so an allOf branch that itself carries allOf is flattened too.
        while (is_array($schema['allOf'] ?? null)) {
            $branches = $schema['allOf'];

            unset($schema['allOf']);

            $merged = [];

            foreach ($branches as $branch) {
                if (is_array($branch)) {
                    [$branch] = $this->inlineRefs($branch, $defs, $seen);
                    $merged = $this->mergeSchema($merged, $branch);
                }
            }

            $schema = $this->mergeSchema($merged, $schema);
        }

        return $schema;
    }

    /**
     * Merge two schema fragments, unioning "required" and "properties" rather
     * than letting one side clobber the other.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeSchema(array $base, array $overlay): array
    {
        $required = array_values(array_unique(array_merge(
            is_array($base['required'] ?? null) ? $base['required'] : [],
            is_array($overlay['required'] ?? null) ? $overlay['required'] : [],
        )));

        $properties = array_merge(
            is_array($base['properties'] ?? null) ? $base['properties'] : [],
            is_array($overlay['properties'] ?? null) ? $overlay['properties'] : [],
        );

        $merged = array_merge($base, $overlay);

        if ($required !== []) {
            $merged['required'] = $required;
        }

        if ($properties !== []) {
            $merged['properties'] = $properties;
        }

        return $merged;
    }

    /**
     * Collapse "anyOf"/"oneOf" to a single branch, since the deserializer only
     * accepts a nullable union (one schema plus a "null" branch).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $defs
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function collapseUnions(array $schema, array $defs, array $seen): array
    {
        foreach (['anyOf', 'oneOf'] as $key) {
            if (! is_array($schema[$key] ?? null)) {
                continue;
            }

            $nullable = false;
            $branches = [];

            foreach ($schema[$key] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                [$branch] = $this->inlineRefs($branch, $defs, $seen);

                if (in_array($branch['type'] ?? null, ['null', ['null']], true)) {
                    $nullable = true;
                } else {
                    $branches[] = $this->node($branch, $defs, $seen);
                }
            }

            unset($schema[$key]);

            if ($branches !== []) {
                $schema = array_merge($schema, $branches[0]);
            }

            if ($nullable) {
                $schema = $this->makeNullable($schema);
            }
        }

        return $schema;
    }

    /**
     * Mark a node nullable in a form the deserializer understands (type + "null").
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function makeNullable(array $schema): array
    {
        $type = $schema['type'] ?? $this->baseType($schema);
        $type = is_array($type) ? $type : [$type];

        $schema['type'] = array_values(array_unique([...$type, 'null']));

        return $schema;
    }

    /**
     * Keep multi-type unions deserializable, and drop "null"-only types which
     * the deserializer cannot represent.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function collapseMultiType(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'null') {
            unset($schema['type']);

            return $schema;
        }

        if (! is_array($type)) {
            return $schema;
        }

        $nonNull = array_values(array_filter($type, fn ($value) => $value !== 'null'));

        if ($nonNull === []) {
            unset($schema['type']);

            return $schema;
        }

        if (count($nonNull) > 1) {
            foreach (self::TYPE_KEYWORDS as $keyword) {
                unset($schema[$keyword]);
            }
        }

        return $schema;
    }

    /**
     * Give a node an explicit, deserializable type. Mirrors the deserializer's
     * own inference so a typeless node never reaches it and throws.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function ensureType(array $schema): array
    {
        if (isset($schema['type']) || isset($schema['anyOf']) || isset($schema['oneOf']) || isset($schema['allOf'])) {
            return $schema;
        }

        $schema['type'] = $this->baseType($schema);

        return $schema;
    }

    /**
     * Infer a single base type from a node's shape, defaulting to "string".
     *
     * @param  array<string, mixed>  $schema
     */
    private function baseType(array $schema): string
    {
        return match (true) {
            isset($schema['properties']), isset($schema['required']) => 'object',
            isset($schema['items']), isset($schema['minItems']), isset($schema['maxItems']), isset($schema['uniqueItems']) => 'array',
            isset($schema['enum']) && is_array($schema['enum']) => $this->inferEnumType($schema['enum']),
            isset($schema['minimum']), isset($schema['maximum']), isset($schema['multipleOf']) => 'number',
            default => 'string',
        };
    }

    /**
     * Infer the scalar type shared by an enum, defaulting to "string" for an
     * empty or heterogeneous enum (which the deserializer cannot type).
     *
     * @param  array<int, mixed>  $enum
     */
    private function inferEnumType(array $enum): string
    {
        $resolved = null;

        foreach ($enum as $value) {
            $current = match (true) {
                is_bool($value) => 'boolean',
                is_int($value) => 'integer',
                is_float($value) => 'number',
                is_string($value) => 'string',
                default => null,
            };

            if ($current === null) {
                return 'string';
            }

            if ($resolved === null || $resolved === $current) {
                $resolved = $current;

                continue;
            }

            if (in_array($resolved, ['integer', 'number'], true) && in_array($current, ['integer', 'number'], true)) {
                $resolved = 'number';

                continue;
            }

            return 'string';
        }

        return $resolved ?? 'string';
    }
}
