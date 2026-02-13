<?php

namespace Laravel\Ai\Gateway\Prism;

class OpenAiCompatiblePrismTool extends PrismTool
{
    /** @var array<string, mixed> */
    protected array $sanitizedSchema = [];

    /** @param array<string, mixed> $schema */
    public function withSanitizedSchema(array $schema): self
    {
        $this->sanitizedSchema = static::sanitize($schema);

        return $this;
    }

    public function hasParameters(): bool
    {
        return ! empty($this->sanitizedSchema)
            && ! empty($this->sanitizedSchema['properties'] ?? []);
    }

    /** @return array<string, array<string, mixed>> */
    public function parametersAsArray(): array
    {
        return $this->sanitizedSchema['properties'] ?? [];
    }

    /** @return array<int, string> */
    public function requiredParameters(): array
    {
        return $this->sanitizedSchema['required'] ?? [];
    }

    /**
     * Recursively strip fields unsupported by strict OpenAI-compatible endpoints.
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
