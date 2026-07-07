<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Concerns\CanBeConcurrent;
use Laravel\Ai\Tools\Request;

#[Strict]
class FixedNumberGenerator implements Tool
{
    use CanBeConcurrent;

    public function __construct(public bool $throwsException = false) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool can be used to generate cryptographically secure random numbers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if ($this->throwsException) {
            throw new \Exception('Forced to throw exception.');
        }

        return 72019;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
