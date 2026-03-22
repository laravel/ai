<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

interface Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string;

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string;

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;
}
