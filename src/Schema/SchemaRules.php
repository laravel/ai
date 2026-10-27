<?php

namespace Laravel\Ai\Schema;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @internal
 */
class SchemaRules
{
    /**
     * Get the validation rules for the properties of the given object schema.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, mixed>>
     */
    public static function for(array $schema): array
    {
        $required = $schema['required'] ?? [];

        return collect($schema['properties'] ?? [])
            ->map(fn (array $property, string $name) => static::rules($property, $name, in_array($name, $required, true)))
            ->reduce(fn (array $rules, array $property) => $rules + $property, []);
    }

    /**
     * Get the rules for one value and everything nested inside it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, mixed>>
     */
    protected static function rules(array $schema, string $attribute, bool $required): array
    {
        $nullable = in_array('null', (array) ($schema['type'] ?? []), true)
            || collect($schema['anyOf'] ?? [])->contains(fn (array $branch) => ($branch['type'] ?? null) === 'null');

        $presence = match (true) {
            $required && $nullable => ['present', 'nullable', static::withoutEmptyString()],
            $required => ['required'],
            $nullable => ['sometimes', 'nullable', static::withoutEmptyString()],
            default => ['sometimes', static::withoutEmptyString()],
        };

        if (isset($schema['anyOf'])) {
            $branches = array_values(array_filter($schema['anyOf'], fn (array $branch) => ($branch['type'] ?? null) !== 'null'));

            return [$attribute => [...$presence, static::anyOf($branches)]];
        }

        $types = array_values(array_diff((array) ($schema['type'] ?? []), ['null']));

        if (count($types) > 1) {
            return [$attribute => [...$presence, static::anyOf(array_map(fn (string $type) => [...$schema, 'type' => $type], $types))]];
        }

        $type = $types[0] ?? null;

        $nested = match ($type) {
            'array' => isset($schema['items']) ? static::rules($schema['items'], "{$attribute}.*", false) : [],
            'object' => collect($schema['properties'] ?? [])
                ->flatMap(fn (array $property, string $name) => static::rules($property, "{$attribute}.{$name}", in_array($name, $schema['required'] ?? [], true)))
                ->all(),
            default => [],
        };

        if ($type === 'array' && ($schema['uniqueItems'] ?? false)) {
            $nested["{$attribute}.*"] = [...$nested["{$attribute}.*"] ?? [], 'distinct'];
        }

        return [$attribute => [...$presence, ...static::typeRules($type, $schema)], ...$nested];
    }

    /**
     * Get the rules a value of the given type must pass.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, mixed>
     */
    protected static function typeRules(?string $type, array $schema): array
    {
        $rules = match ($type) {
            'string' => ['string', ...static::bounds($schema, 'minLength', 'maxLength'), ...static::stringRules($schema)],
            'integer' => ['integer', ...static::bounds($schema, 'minimum', 'maximum'), ...static::multipleOf($schema)],
            'number' => ['numeric', ...static::bounds($schema, 'minimum', 'maximum'), ...static::multipleOf($schema)],
            'boolean' => ['boolean'],
            'array' => ['list', ...static::bounds($schema, 'minItems', 'maxItems')],
            'object' => ($schema['additionalProperties'] ?? null) === false && isset($schema['properties'])
                ? ['array:'.implode(',', array_keys($schema['properties']))]
                : ['array'],
            default => [],
        };

        return isset($schema['enum']) ? [...$rules, Rule::in($schema['enum'])] : $rules;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function bounds(array $schema, string $min, string $max): array
    {
        return array_values(array_filter([
            isset($schema[$min]) ? "min:{$schema[$min]}" : null,
            isset($schema[$max]) ? "max:{$schema[$max]}" : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function multipleOf(array $schema): array
    {
        return isset($schema['multipleOf']) ? ["multiple_of:{$schema['multipleOf']}"] : [];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function stringRules(array $schema): array
    {
        return array_values(array_filter([
            isset($schema['pattern']) ? 'regex:/'.preg_replace('~(?<!\\\\)/~', '\\/', $schema['pattern']).'/u' : null,
            match ($schema['format'] ?? null) {
                'email' => 'email',
                'uri', 'url' => 'url',
                'uuid' => 'uuid',
                'date' => 'date_format:Y-m-d',
                'date-time' => 'date',
                default => null,
            },
        ]));
    }

    /**
     * Build a rule that refuses an empty string, which Laravel otherwise lets skip every non-implicit rule.
     */
    protected static function withoutEmptyString(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public bool $implicit = true;

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if ($value === '') {
                    $fail('validation.filled')->translate();
                }
            }
        };
    }

    /**
     * Build a rule that passes when the value matches any of the given schemas.
     *
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected static function anyOf(array $branches): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($branches) {
            $matches = collect($branches)->contains(
                fn (array $branch) => Validator::make(['value' => $value], static::rules($branch, 'value', true))->passes(),
            );

            if (! $matches) {
                $fail('The :attribute field is invalid.');
            }
        };
    }
}
