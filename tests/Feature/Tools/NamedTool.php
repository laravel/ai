<?php

namespace Tests\Feature\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Concerns\HasCustomName;
use Laravel\Ai\Tools\Request;

class NamedTool implements Tool
{
    use HasCustomName;

    public function __construct(public bool $throwsException = false) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This is a tool with a custom name.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if ($this->throwsException) {
            throw new \Exception('Forced to throw exception.');
        }

        return 'Output from the tool';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
