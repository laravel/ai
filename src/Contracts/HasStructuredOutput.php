<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;

interface HasStructuredOutput
{
    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array|Schemable;
}
