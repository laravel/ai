<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

interface NeedsInput
{
    /**
     * Get the schema for the values the caller must supply before the tool may run.
     *
     * @return array<string, mixed>
     */
    public function needsInput(JsonSchema $schema, Request $request): array;
}
