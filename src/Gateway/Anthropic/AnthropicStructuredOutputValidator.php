<?php

namespace Laravel\Ai\Gateway\Anthropic;

class AnthropicStructuredOutputValidator
{
    /**
     * Validate structured output data against the value constraints (minimum/maximum, string
     * length, array bounds, etc.) that Anthropic's native structured output accepts but does
     * not enforce on the model's behalf. The structural contract -- types, required properties,
     * enums -- is already guaranteed by the API itself, so it is intentionally not re-checked here.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return array<int, string> Human-readable violation messages; empty when the data is valid.
     */
    public static function violations(array $data, array $schema): array
    {
        return static::node($data, $schema, '');
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function node(mixed $value, array $schema, string $path): array
    {
        if ($value === null) {
            return [];
        }

        return match (true) {
            is_int($value), is_float($value) => static::numericViolations($value, $schema, $path),
            is_string($value) => static::stringViolations($value, $schema, $path),
            is_array($value) && array_is_list($value) => static::arrayViolations($value, $schema, $path),
            is_array($value) => static::objectViolations($value, $schema, $path),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function numericViolations(int|float $value, array $schema, string $path): array
    {
        $violations = [];

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $violations[] = static::describe($path, "must be at least {$schema['minimum']} (got {$value})");
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $violations[] = static::describe($path, "must be at most {$schema['maximum']} (got {$value})");
        }

        if (isset($schema['multipleOf']) && (float) $schema['multipleOf'] !== 0.0
            && abs(fmod($value, $schema['multipleOf'])) > 1e-9) {
            $violations[] = static::describe($path, "must be a multiple of {$schema['multipleOf']} (got {$value})");
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function stringViolations(string $value, array $schema, string $path): array
    {
        $violations = [];
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $violations[] = static::describe($path, "must be at least {$schema['minLength']} character(s) (got {$length})");
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $violations[] = static::describe($path, "must be at most {$schema['maxLength']} character(s) (got {$length})");
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && static::failsPattern($value, $schema['pattern'])) {
            $violations[] = static::describe($path, "must match the pattern: {$schema['pattern']}");
        }

        return $violations;
    }

    /**
     * @param  array<int, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function arrayViolations(array $value, array $schema, string $path): array
    {
        $violations = [];
        $count = count($value);

        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $violations[] = static::describe($path, "must contain at least {$schema['minItems']} item(s) (got {$count})");
        }

        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $violations[] = static::describe($path, "must contain at most {$schema['maxItems']} item(s) (got {$count})");
        }

        if (($schema['uniqueItems'] ?? false) === true) {
            $serialized = array_map(fn ($item) => json_encode($item), $value);

            if (count($serialized) !== count(array_unique($serialized))) {
                $violations[] = static::describe($path, 'items must be unique');
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($violations, ...static::node($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    protected static function objectViolations(array $value, array $schema, string $path): array
    {
        $violations = [];

        foreach ($schema['properties'] ?? [] as $key => $propertySchema) {
            if (array_key_exists($key, $value) && is_array($propertySchema)) {
                array_push($violations, ...static::node($value[$key], $propertySchema, static::join($path, $key)));
            }
        }

        return $violations;
    }

    /**
     * Determine if the string value fails to match the given JSON Schema pattern.
     *
     * Invalid or unsupported regex syntax is treated as non-blocking rather than a violation.
     */
    protected static function failsPattern(string $value, string $pattern): bool
    {
        $result = @preg_match('/'.str_replace('/', '\/', $pattern).'/u', $value);

        return $result === 0;
    }

    /**
     * Join a property key onto a dotted path.
     */
    protected static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }

    /**
     * Prefix a violation message with its schema path, when known.
     */
    protected static function describe(string $path, string $message): string
    {
        return $path === '' ? $message : "`{$path}` {$message}";
    }
}
