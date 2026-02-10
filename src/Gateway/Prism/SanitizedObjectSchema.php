<?php

namespace Laravel\Ai\Gateway\Prism;

use Laravel\Ai\ObjectSchema;

class SanitizedObjectSchema extends ObjectSchema
{
    /**
     * Get the sanitized array representation of the schema.
     *
     * Recursively strips `name` and `additionalProperties` fields
     * that are unsupported by strict OpenAI-compatible endpoints.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return static::sanitize(parent::toSchema());
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return $this->toSchema();
    }

    /**
     * Recursively sanitize the given schema array by stripping
     * unsupported fields (`name`, `additionalProperties`).
     *
     * @param  array<string|int, mixed>  $schema
     * @return array<string|int, mixed>
     */
    public static function sanitize(array $schema): array
    {
        unset($schema['name'], $schema['additionalProperties']);

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = static::sanitize($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = static::sanitize($schema['items']);
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $index => $variant) {
                if (is_array($variant)) {
                    $schema['anyOf'][$index] = static::sanitize($variant);
                }
            }
        }

        return $schema;
    }
}
