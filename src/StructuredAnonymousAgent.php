<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Schemable;
use Laravel\SerializableClosure\SerializableClosure;

class StructuredAnonymousAgent extends AnonymousAgent implements HasStructuredOutput
{
    public array|Schemable|SerializableClosure $schema;

    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        Closure|array|Schemable $schema,
    ) {
        $this->schema = $schema instanceof Closure
            ? new SerializableClosure($schema)
            : $schema;
    }

    public function schema(JsonSchema $schema): array|Schemable
    {
        return $this->schema instanceof SerializableClosure
            ? call_user_func($this->schema, $schema)
            : $this->schema;
    }
}
