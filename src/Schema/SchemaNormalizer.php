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
     * Keywords the deserializer cannot represent and that are dropped.
     */
    private const UNSUPPORTED = [
        '$schema', '$id', '$anchor', '$comment', 'not', 'if', 'then', 'else',
        'patternProperties', 'dependentSchemas', 'dependentRequired', 'unevaluatedProperties',
        'contains', 'minContains', 'maxContains', 'prefixItems', 'examples', 'deprecated',
        'readOnly', 'writeOnly', 'minProperties', 'maxProperties', 'exclusiveMinimum', 'exclusiveMaximum',
    ];

    /**
     * Scalar types that can be combined into a multi-type union.
     */
    private const SCALAR_TYPES = ['string', 'integer', 'number', 'boolean'];

    /**
     * The JSON Schema type strings the deserializer understands.
     */
    private const TYPES = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null'];

    /**
     * Rewrite a raw JSON Schema into the subset Illuminate\JsonSchema can deserialize.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        return (new self)->node($schema, $schema, []);
    }

    /**
     * Normalize a single schema node into the deserializable subset.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function node(array $schema, array $root, array $seen): array
    {
        [$schema, $seen] = $this->inlineRefs($schema, $root, $seen);

        $schema = $this->mergeAllOf($schema, $root, $seen);
        $schema = $this->collapseUnions($schema, $root, $seen);
        $schema = $this->collapseMultiType($schema);
        $schema = $this->dropUnsupportedKeywords($schema);
        $schema = $this->rewriteConstAndDefault($schema);
        $schema = $this->normalizeAdditionalProperties($schema);
        $schema = $this->normalizeChildren($schema, $root, $seen);

        return $this->ensureType($schema);
    }

    /**
     * Inline local "$ref" pointers (cycle-safe); drop remote or unresolvable ones.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array{0: array<string, mixed>, 1: array<string, true>}
     */
    private function inlineRefs(array $schema, array $root, array $seen): array
    {
        while (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $ref = $schema['$ref'];

            unset($schema['$ref']);

            if (isset($seen[$ref]) || ($resolved = $this->lookupRef($ref, $root)) === null) {
                break;
            }

            $seen[$ref] = true;
            $schema = array_merge($resolved, $schema);
        }

        return [$schema, $seen];
    }

    /**
     * Resolve a local JSON pointer against the root schema.
     *
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>|null
     */
    private function lookupRef(string $ref, array $root): ?array
    {
        if (! str_starts_with($ref, '#/')) {
            return null;
        }

        $target = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return null;
            }

            $target = $target[$segment];
        }

        return is_array($target) ? $target : null;
    }

    /**
     * Flatten "allOf" branches into the node; the deserializer has no intersection type.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function mergeAllOf(array $schema, array $root, array $seen): array
    {
        while (is_array($schema['allOf'] ?? null)) {
            $branches = $schema['allOf'];

            unset($schema['allOf']);

            $merged = [];

            foreach ($branches as $branch) {
                if (is_array($branch)) {
                    [$branch, $seen] = $this->inlineRefs($branch, $root, $seen);
                    $merged = $this->mergeSchema($merged, $branch);
                }
            }

            $schema = $this->mergeSchema($merged, $schema);
        }

        return $schema;
    }

    /**
     * Merge two schema fragments, unioning "required" and recursively merging "properties".
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeSchema(array $base, array $overlay): array
    {
        $required = array_values(array_unique(array_merge(
            $this->arrayValue($base, 'required'),
            $this->arrayValue($overlay, 'required'),
        )));

        $properties = $this->arrayValue($base, 'properties');

        foreach ($this->arrayValue($overlay, 'properties') as $key => $value) {
            $properties[$key] = isset($properties[$key]) && is_array($properties[$key]) && is_array($value)
                ? $this->mergeSchema($properties[$key], $value)
                : $value;
        }

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
     * Collapse "anyOf"/"oneOf" into a deserializable form.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function collapseUnions(array $schema, array $root, array $seen): array
    {
        foreach (['anyOf', 'oneOf'] as $key) {
            if (! is_array($schema[$key] ?? null)) {
                continue;
            }

            $branches = $schema[$key];

            unset($schema[$key]);

            $schema = $this->mergeUnion($schema, $branches, $root, $seen);
        }

        return $schema;
    }

    /**
     * Reduce union branches into the node: a scalar multi-type union, or the first
     * branch (lossy) since the deserializer only accepts a single schema plus null.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, mixed>  $branches
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function mergeUnion(array $schema, array $branches, array $root, array $seen): array
    {
        $nullable = false;
        $resolved = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            [$branch, $branchSeen] = $this->inlineRefs($branch, $root, $seen);

            if (in_array($branch['type'] ?? null, ['null', ['null']], true)) {
                $nullable = true;
            } else {
                $resolved[] = $this->node($branch, $root, $branchSeen);
            }
        }

        if (($scalarTypes = $this->scalarUnionTypes($resolved)) !== null) {
            $schema['type'] = array_values(array_unique($nullable ? [...$scalarTypes, 'null'] : $scalarTypes));

            return $schema;
        }

        if ($resolved !== []) {
            $schema = array_merge($schema, $resolved[0]);
        }

        return $nullable ? $this->makeNullable($schema) : $schema;
    }

    /**
     * Get the unioned scalar types when every branch is a plain scalar, else null.
     *
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, string>|null
     */
    private function scalarUnionTypes(array $branches): ?array
    {
        if (count($branches) < 2) {
            return null;
        }

        $types = [];

        foreach ($branches as $branch) {
            $branchTypes = is_array($branch['type'] ?? null) ? $branch['type'] : [$branch['type'] ?? null];

            foreach ($branchTypes as $type) {
                if (! in_array($type, self::SCALAR_TYPES, true)) {
                    return null;
                }

                $types[] = $type;
            }
        }

        return $types;
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
     * Keep multi-type unions deserializable and drop unrepresentable "null"-only types.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function collapseMultiType(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (! is_array($type)) {
            if ($type === 'null' || ($type !== null && ! in_array($type, self::TYPES, true))) {
                unset($schema['type']);
            }

            return $schema;
        }

        $valid = array_values(array_filter($type, fn ($value) => in_array($value, self::TYPES, true)));
        $nonNull = array_values(array_filter($valid, fn ($value) => $value !== 'null'));

        if ($nonNull === []) {
            unset($schema['type']);

            return $schema;
        }

        $schema['type'] = count($valid) === 1 ? $valid[0] : $valid;

        if (count($nonNull) > 1) {
            foreach (self::TYPE_KEYWORDS as $keyword) {
                unset($schema[$keyword]);
            }
        }

        return $schema;
    }

    /**
     * Drop keywords the deserializer cannot represent, along with definitions.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function dropUnsupportedKeywords(array $schema): array
    {
        foreach (self::UNSUPPORTED as $keyword) {
            unset($schema[$keyword]);
        }

        unset($schema['$defs'], $schema['definitions']);

        return $schema;
    }

    /**
     * Rewrite "const" to a single-value enum and drop an unsupported null default.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function rewriteConstAndDefault(array $schema): array
    {
        if (array_key_exists('const', $schema)) {
            $schema['enum'] = [$schema['const']];

            unset($schema['const']);
        }

        if (array_key_exists('default', $schema) && $schema['default'] === null) {
            unset($schema['default']);
        }

        return $schema;
    }

    /**
     * Infer an object type from additionalProperties, then drop its permissive forms.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeAdditionalProperties(array $schema): array
    {
        if (! isset($schema['type']) && array_key_exists('additionalProperties', $schema)) {
            $schema['type'] = 'object';
        }

        if (($schema['additionalProperties'] ?? false) !== false) {
            unset($schema['additionalProperties']);
        }

        return $schema;
    }

    /**
     * Recurse into "properties" (pruning orphaned "required") and "items".
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @param  array<string, true>  $seen
     * @return array<string, mixed>
     */
    private function normalizeChildren(array $schema, array $root, array $seen): array
    {
        if (is_array($schema['properties'] ?? null)) {
            $properties = [];

            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition)) {
                    $properties[$key] = $this->node($definition, $root, $seen);
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
            $schema['items'] = is_array($schema['items']) && ! array_is_list($schema['items'])
                ? $this->node($schema['items'], $root, $seen)
                : null;

            if ($schema['items'] === null) {
                unset($schema['items']);
            }
        }

        return $schema;
    }

    /**
     * Give a node an explicit type so a typeless node never reaches the deserializer.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function ensureType(array $schema): array
    {
        if ($this->usableType($schema['type'] ?? null) || isset($schema['anyOf']) || isset($schema['oneOf']) || isset($schema['allOf'])) {
            return $schema;
        }

        unset($schema['type']);

        $schema['type'] = $this->baseType($schema);

        return $schema;
    }

    /**
     * Determine whether a node's type resolves to a non-null type the deserializer accepts.
     */
    private function usableType(mixed $type): bool
    {
        $types = array_filter(is_array($type) ? $type : [$type], fn ($value) => $value !== 'null');

        return $types !== [] && array_filter($types, fn ($value) => ! in_array($value, self::TYPES, true)) === [];
    }

    /**
     * Infer a single base type from a node's shape, defaulting to "string".
     *
     * @param  array<string, mixed>  $schema
     */
    private function baseType(array $schema): string
    {
        return match (true) {
            isset($schema['properties']), isset($schema['required']), isset($schema['additionalProperties']) => 'object',
            isset($schema['items']), isset($schema['minItems']), isset($schema['maxItems']), isset($schema['uniqueItems']) => 'array',
            isset($schema['enum']) && is_array($schema['enum']) => $this->inferEnumType($schema['enum']),
            isset($schema['minimum']), isset($schema['maximum']), isset($schema['multipleOf']) => 'number',
            default => 'string',
        };
    }

    /**
     * Infer the scalar type shared by an enum, defaulting to "string".
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

    /**
     * Read an array-typed key, defaulting to an empty array.
     *
     * @param  array<string, mixed>  $schema
     * @return array<mixed>
     */
    private function arrayValue(array $schema, string $key): array
    {
        return is_array($schema[$key] ?? null) ? $schema[$key] : [];
    }
}
