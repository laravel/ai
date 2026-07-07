<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Concerns\CanBeConcurrent;
use Laravel\Ai\Tools\Request;

class NestedObjectTool implements Tool
{
    use CanBeConcurrent;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'A tool with nested object parameters for testing.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
