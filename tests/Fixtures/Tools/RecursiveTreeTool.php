<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[Strict]
class RecursiveTreeTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Store a tree of labelled nodes.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'stored';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tree' => $schema->object([
                'label' => $schema->string()->required(),
                'children' => $schema->array()->items($schema->ref('node'))->required(),
            ])->define('node')->required(),
        ];
    }
}
