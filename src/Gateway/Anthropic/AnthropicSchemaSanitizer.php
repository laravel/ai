<?php

namespace Laravel\Ai\Gateway\Anthropic;

class AnthropicSchemaSanitizer
{
    /**
     * Numeric constraints rejected by Anthropic native structured output.
     */
    protected const NUMERIC_KEYWORDS = [
        'minimum', 'maximum', 'multipleOf', 'exclusiveMinimum', 'exclusiveMaximum',
    ];

    /**
     * String constraints rejected by Anthropic native structured output.
     */
    protected const STRING_KEYWORDS = ['minLength', 'maxLength'];

    /**
     * String formats accepted by Anthropic native structured output.
     */
    protected const SUPPORTED_FORMATS = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Strip JSON Schema keywords unsupported by Anthropic native structured output,
     * folding each into the node's description so the model still honors the intent.
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
        $notes = [];

        foreach (static::NUMERIC_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $notes[] = static::numericNote($keyword, $schema[$keyword]);
                unset($schema[$keyword]);
            }
        }

        foreach (static::STRING_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $notes[] = static::stringNote($keyword, $schema[$keyword]);
                unset($schema[$keyword]);
            }
        }

        if (array_key_exists('maxItems', $schema)) {
            $notes[] = "Must contain at most {$schema['maxItems']} items.";
            unset($schema['maxItems']);
        }

        if (array_key_exists('minItems', $schema) && $schema['minItems'] > 1) {
            $notes[] = "Must contain at least {$schema['minItems']} items.";
            $schema['minItems'] = 1;
        }

        if (array_key_exists('uniqueItems', $schema)) {
            if ($schema['uniqueItems'] === true) {
                $notes[] = 'All items must be unique.';
            }

            unset($schema['uniqueItems']);
        }

        if (array_key_exists('format', $schema)
            && ! in_array($schema['format'], static::SUPPORTED_FORMATS, true)) {
            $notes[] = "Format: {$schema['format']}.";
            unset($schema['format']);
        }

        if (filled($notes)) {
            $schema['description'] = trim(
                ($schema['description'] ?? '').' '.implode(' ', $notes)
            );
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = static::node($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = static::node($schema['items']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $combinator) {
            if (isset($schema[$combinator]) && is_array($schema[$combinator])) {
                $schema[$combinator] = array_map(
                    fn ($branch) => is_array($branch) ? static::node($branch) : $branch,
                    $schema[$combinator],
                );
            }
        }

        return $schema;
    }

    /**
     * Format a natural-language note for a numeric constraint.
     */
    protected static function numericNote(string $keyword, mixed $value): string
    {
        return match ($keyword) {
            'minimum' => "Must be at least {$value}.",
            'maximum' => "Must be at most {$value}.",
            'multipleOf' => "Must be a multiple of {$value}.",
            'exclusiveMinimum' => "Must be greater than {$value}.",
            'exclusiveMaximum' => "Must be less than {$value}.",
        };
    }

    /**
     * Format a natural-language note for a string-length constraint.
     */
    protected static function stringNote(string $keyword, mixed $value): string
    {
        $unit = $value === 1 ? 'character' : 'characters';

        return match ($keyword) {
            'minLength' => "Must be at least {$value} {$unit}.",
            'maxLength' => "Must be at most {$value} {$unit}.",
        };
    }
}
