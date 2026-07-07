<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

interface Tool
{
    /**
     * Mark the tool as safe to execute alongside other concurrent tools.
     */
    public function concurrent(bool $concurrent = true): static;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string;

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string;

    /**
     * Determine whether the tool is safe to execute concurrently.
     */
    public function isConcurrent(): bool;

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;
}
