<?php

namespace Laravel\Ai;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Schemable;
use Prism\Prism\Contracts\Schema as PrismSchema;

class Schema implements PrismSchema, Schemable
{
    /**
     * Create a new output schema.
     */
    public function __construct(
        public Type $schema,
        public string $name = 'schema_definition',
        public bool $strict = true
    ) {}

    /**
     * Get the name of the schema.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Create a new output schema with the given name.
     */
    public function withName(string $name): self
    {
        return new static(
            $this->schema,
            $name,
            $this->strict,
        );
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
     * Get the array representation of the schema.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'name' => $this->name,
            ...$this->schema->toArray(),
        ];
    }
}
