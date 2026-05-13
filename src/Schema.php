<?php

namespace Laravel\Ai;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Schemable;

class Schema implements Schemable
{
    /**
     * Create a new output schema.
     */
    public function __construct(
        public Type $schema,
        public string $name = 'schema_definition',
        public bool $strict = false
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
        return new self(
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
        return $this->schema->toArray();
    }
}
