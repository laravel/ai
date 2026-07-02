<?php

namespace Laravel\Ai\Gateway\Anthropic;

class AnthropicSchemaSanitizer
{
    /**
     * Numeric constraint keywords rejected by Anthropic's native structured output.
     */
    protected const NUMERIC_KEYWORDS = ['minimum', 'maximum', 'multipleOf'];

    /**
     * String length constraint keywords rejected by Anthropic's native structured output.
     */
    protected const STRING_LENGTH_KEYWORDS = ['minLength', 'maxLength'];

    /**
     * String formats accepted by Anthropic's native structured output.
     */
    protected const SUPPORTED_FORMATS = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Strip JSON Schema keywords unsupported by Anthropic's native structured output, folding
     * each removed constraint into the node's description so the model still honors it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function sanitize(array $schema): array
    {
        return static::node($schema);
    }

    /**
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

        foreach (static::STRING_LENGTH_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $notes[] = static::lengthNote($keyword, $schema[$keyword]);
                unset($schema[$keyword]);
            }
        }

        if (array_key_exists('maxItems', $schema)) {
            $notes[] = "Must contain at most {$schema['maxItems']} item(s).";
            unset($schema['maxItems']);
        }

        // Anthropic's native structured output only accepts minItems of 0 or 1.
        if (array_key_exists('minItems', $schema) && $schema['minItems'] > 1) {
            $notes[] = "Must contain at least {$schema['minItems']} item(s).";
            $schema['minItems'] = 1;
        }

        if (array_key_exists('uniqueItems', $schema)) {
            if ($schema['uniqueItems'] === true) {
                $notes[] = 'All items must be unique.';
            }

            unset($schema['uniqueItems']);
        }

        if (array_key_exists('format', $schema) && ! in_array($schema['format'], static::SUPPORTED_FORMATS, true)) {
            $notes[] = "Format: {$schema['format']}.";
            unset($schema['format']);
        }

        if (filled($notes)) {
            $schema['description'] = trim(($schema['description'] ?? '').' '.implode(' ', $notes));
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

        return $schema;
    }

    /**
     * Format a natural-language note describing a stripped numeric constraint.
     */
    protected static function numericNote(string $keyword, int|float $value): string
    {
        return match ($keyword) {
            'minimum' => "Must be at least {$value}.",
            'maximum' => "Must be at most {$value}.",
            'multipleOf' => "Must be a multiple of {$value}.",
        };
    }

    /**
     * Format a natural-language note describing a stripped string-length constraint.
     */
    protected static function lengthNote(string $keyword, int $value): string
    {
        $unit = $value === 1 ? 'character' : 'characters';

        return $keyword === 'minLength'
            ? "Must be at least {$value} {$unit}."
            : "Must be at most {$value} {$unit}.";
    }
}
