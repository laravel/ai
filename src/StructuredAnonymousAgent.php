<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\SerializableClosure\SerializableClosure;

class StructuredAnonymousAgent extends AnonymousAgent implements HasStructuredOutput
{
    public SerializableClosure $schema;

    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        Closure $schema,
    ) {
        $this->schema = new SerializableClosure($schema);
    }

    public function schema(JsonSchema $schema): array
    {
        return call_user_func($this->schema, $schema);
    }
}
