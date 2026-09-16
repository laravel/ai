<?php

namespace Laravel\Ai\Gateway\Anthropic;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AnthropicSchemaSanitizer
{
    /**
     * Numeric constraints rejected by Anthropic native structured output.
     */
    protected const NUMERIC_KEYWORDS = [
        'minimum', 'exclusiveMinimum', 'maximum', 'exclusiveMaximum', 'multipleOf',
    ];

    /**
     * String constraints rejected by Anthropic native structured output.
     */
    protected const STRING_KEYWORDS = ['minLength', 'maxLength', 'pattern'];

    /**
     * Array constraints rejected beyond a "minItems" of 0 or 1.
     */
    protected const ARRAY_KEYWORDS = [
        'maxItems', 'uniqueItems', 'contains', 'minContains', 'maxContains',
        'prefixItems', 'unevaluatedItems',
    ];

    /**
     * Object constraints rejected beyond "properties", "required" and "additionalProperties".
     */
    protected const OBJECT_KEYWORDS = [
        'minProperties', 'maxProperties', 'patternProperties', 'propertyNames',
        'dependentRequired', 'dependentSchemas', 'unevaluatedProperties',
    ];

    /**
     * Subschema, annotation and identifier keywords outside the accepted subset.
     */
    protected const SCHEMA_KEYWORDS = [
        'not', 'if', 'then', 'else',
        'examples', 'deprecated', 'readOnly', 'writeOnly',
        'contentEncoding', 'contentMediaType', 'contentSchema',
        '$schema', '$id', '$anchor', '$comment', '$dynamicRef', '$dynamicAnchor', '$vocabulary',
    ];

    /**
     * Every keyword Anthropic native structured output rejects.
     */
    protected const REJECTED_KEYWORDS = [
        ...self::NUMERIC_KEYWORDS,
        ...self::STRING_KEYWORDS,
        ...self::ARRAY_KEYWORDS,
        ...self::OBJECT_KEYWORDS,
        ...self::SCHEMA_KEYWORDS,
    ];

    /**
     * String formats accepted by Anthropic native structured output.
     */
    protected const SUPPORTED_FORMATS = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Strip the keywords Anthropic native structured output rejects, folding each into the node's description so the model still honors the intent.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function sanitize(array $schema): array
    {
        return static::node($schema);
    }

    /**
     * Sanitize a single schema node, then recurse into its children.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function node(array $schema): array
    {
        $schema = static::expandUnionType($schema);

        $notes = [];

        if (array_key_exists('minItems', $schema) && $schema['minItems'] > 1) {
            $notes[] = "Must contain at least {$schema['minItems']} items.";
            $schema['minItems'] = 1;
        }

        if (array_key_exists('format', $schema)
            && ! in_array($schema['format'], static::SUPPORTED_FORMATS, true)) {
            $notes[] = "Format: {$schema['format']}.";
            unset($schema['format']);
        }

        if (array_key_exists('enum', $schema) && ! static::isSupportedEnum($schema['enum'])) {
            $notes[] = 'Must be one of: '.json_encode($schema['enum']).'.';
            unset($schema['enum']);
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $schema['additionalProperties'] = false;
        }

        if (is_array($schema['oneOf'] ?? null)) {
            $schema['anyOf'] = array_merge($schema['anyOf'] ?? [], $schema['oneOf']);
            unset($schema['oneOf']);
        }

        foreach (static::REJECTED_KEYWORDS as $keyword) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }

            if (filled($note = static::note($keyword, $schema[$keyword]))) {
                $notes[] = $note;
            }

            unset($schema[$keyword]);
        }

        return static::children(static::describe($schema, $notes));
    }

    /**
     * Recurse into every child schema of the given node.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function children(array $schema): array
    {
        $sanitize = fn (mixed $child): mixed => is_array($child) ? static::node($child) : $child;

        foreach (['properties', '$defs', 'definitions', 'anyOf', 'allOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                $schema[$keyword] = array_map($sanitize, $schema[$keyword]);
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = array_is_list($schema['items'])
                ? array_map($sanitize, $schema['items'])
                : static::node($schema['items']);
        }

        return $schema;
    }

    /**
     * Rewrite a union "type" array into the anyOf form Anthropic documents.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function expandUnionType(array $schema): array
    {
        if (! is_array($schema['type'] ?? null)) {
            return $schema;
        }

        $types = array_values(array_unique($schema['type']));

        if (count($types) === 1) {
            return [...$schema, 'type' => $types[0]];
        }

        $description = $schema['description'] ?? null;

        unset($schema['type'], $schema['description']);

        $branches = array_map(
            fn (string $type) => $type === 'null' ? ['type' => 'null'] : ['type' => $type, ...$schema],
            $types,
        );

        return Arr::whereNotNull([
            'anyOf' => $branches,
            'description' => $description,
        ]);
    }

    /**
     * Append the given constraint notes to the node's description.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $notes
     * @return array<string, mixed>
     */
    protected static function describe(array $schema, array $notes): array
    {
        if (blank($notes)) {
            return $schema;
        }

        $description = trim((string) ($schema['description'] ?? ''));

        if (filled($description) && ! Str::endsWith($description, ['.', '!', '?', ':', ';'])) {
            $description .= '.';
        }

        $schema['description'] = trim($description.' '.implode(' ', $notes));

        return $schema;
    }

    /**
     * Format a natural-language note for a constraint Anthropic rejects.
     */
    protected static function note(string $keyword, mixed $value): ?string
    {
        if (in_array($keyword, static::NUMERIC_KEYWORDS, true) && ! is_numeric($value)) {
            return null;
        }

        return match ($keyword) {
            'minimum' => "Must be at least {$value}.",
            'exclusiveMinimum' => "Must be greater than {$value}.",
            'maximum' => "Must be at most {$value}.",
            'exclusiveMaximum' => "Must be less than {$value}.",
            'multipleOf' => "Must be a multiple of {$value}.",
            'minLength' => 'Must be at least '.static::characters($value).'.',
            'maxLength' => 'Must be at most '.static::characters($value).'.',
            'pattern' => "Must match the pattern {$value}.",
            'maxItems' => "Must contain at most {$value} items.",
            'minContains' => "Must contain at least {$value} matching items.",
            'maxContains' => "Must contain at most {$value} matching items.",
            'uniqueItems' => $value === true ? 'All items must be unique.' : null,
            'minProperties' => "Must have at least {$value} properties.",
            'maxProperties' => "Must have at most {$value} properties.",
            default => null,
        };
    }

    /**
     * Pluralize a character count for a string-length note.
     */
    protected static function characters(mixed $value): string
    {
        return $value.' '.Str::plural('character', (int) $value);
    }

    /**
     * Determine if every member of the given enum is a type Anthropic accepts.
     */
    protected static function isSupportedEnum(mixed $enum): bool
    {
        return is_array($enum) && collect($enum)->every(
            fn ($member) => is_scalar($member) || is_null($member)
        );
    }
}
