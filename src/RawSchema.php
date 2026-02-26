<?php

namespace Laravel\Ai;

use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Contracts\Schemable;
use Prism\Prism\Contracts\Schema as PrismSchema;

class RawSchema implements PrismSchema, Schemable
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        protected array $schema,
        protected string $name = 'schema_definition',
    ) {}

    /**
     * Create a new schema from an array.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function fromArray(array $schema, string $name = 'schema_definition'): self
    {
        return new self($schema, $name);
    }

    /**
     * Create a new schema from a JSON string.
     *
     * @throws JsonException
     */
    public static function fromJson(string $json, string $name = 'schema_definition'): self
    {
        $schema = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($schema)) {
            throw new InvalidArgumentException('The provided JSON schema must decode to an array.');
        }

        return new self($schema, $name);
    }

    /**
     * Create a new schema from a JSON file.
     *
     * @throws JsonException
     */
    public static function fromFile(string $path, string $name = 'schema_definition'): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("The schema file [{$path}] does not exist or is not readable.");
        }

        $json = file_get_contents($path);

        if ($json === false) {
            throw new InvalidArgumentException("Unable to read schema file [{$path}].");
        }

        return static::fromJson($json, $name);
    }

    /**
     * Get the schema name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Create a new schema with the given name.
     */
    public function withName(string $name): self
    {
        return new self($this->schema, $name);
    }

    /**
     * Get the raw schema definition without the schema name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->schema;
    }

    /**
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toSchema();
    }

    /**
     * Get the schema payload including name.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        if (isset($this->schema['name']) && is_string($this->schema['name'])) {
            return $this->schema;
        }

        return [
            'name' => $this->name,
            ...$this->schema,
        ];
    }
}
