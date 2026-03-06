<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Container\Container;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\BooleanType;
use Illuminate\JsonSchema\Types\IntegerType;
use Illuminate\JsonSchema\Types\NumberType;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;

/**
 * Get an ad-hoc agent instance.
 */
function agent(
    string $instructions = '',
    iterable $messages = [],
    iterable $tools = [],
    ?Closure $schema = null,
): Agent {
    return $schema
        ? new StructuredAnonymousAgent($instructions, $messages, $tools, $schema)
        : new AnonymousAgent($instructions, $messages, $tools);
}

/**
 * Get a new pipeline instance.
 */
function pipeline(): Pipeline
{
    return new Pipeline(Container::getInstance());
}

/**
 * Generate a new ULID.
 */
function ulid(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * Generate fake data from a JSON schema type.
 */
function generate_fake_data_for_json_schema_type(Type $type): mixed
{
    $attributes = (fn () => get_object_vars($type))->call($type);

    if (isset($attributes['enum']) && is_array($attributes['enum']) && count($attributes['enum']) > 0) {
        $enumValue = $attributes['enum'][array_rand($attributes['enum'])];

        return $type::class === ArrayType::class
            ? [$enumValue]
            : $enumValue;
    }

    if (isset($attributes['default'])) {
        return $attributes['default'];
    }

    return match ($type::class) {
        ObjectType::class => (function () use ($attributes) {
            $result = [];

            foreach ($attributes['properties'] ?? [] as $key => $property) {
                $result[$key] = generate_fake_data_for_json_schema_type($property);
            }

            return $result;
        })(),

        ArrayType::class => (function () use ($attributes) {
            $min = $attributes['minItems'] ?? 1;
            $max = $attributes['maxItems'] ?? max($min, 3);

            $count = random_int($min, $max);

            if (! isset($attributes['items'])) {
                return [];
            }

            $result = [];

            for ($i = 0; $i < $count; $i++) {
                $result[] = generate_fake_data_for_json_schema_type(
                    $attributes['items']
                );
            }

            return $result;
        })(),

        StringType::class => (function () use ($attributes) {
            if (isset($attributes['format'])) {
                return match ($attributes['format']) {
                    'date' => date('Y-m-d'),
                    'date-time' => date('c'),
                    'email' => 'user@example.com',
                    'time' => date('H:i:s'),
                    'uri', 'url' => 'https://example.com',
                    'uuid' => (string) Str::uuid(),
                    default => 'string',
                };
            }

            $min = $attributes['minLength'] ?? 1;
            $max = $attributes['maxLength'] ?? max($min, 10);

            return Str::random(random_int($min, $max));
        })(),

        IntegerType::class => (function () use ($attributes) {
            $min = $attributes['minimum'] ?? 0;
            $max = $attributes['maximum'] ?? max($min, 100);

            return random_int($min, $max);
        })(),

        NumberType::class => (function () use ($attributes) {
            $min = $attributes['minimum'] ?? 0.0;
            $max = $attributes['maximum'] ?? max($min, 100.0);

            return $min + mt_rand() / mt_getrandmax() * ($max - $min);
        })(),

        BooleanType::class => random_int(0, 1) === 0,

        default => null,
    };
}
