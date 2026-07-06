<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\ClientSideTool;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[ClientSideTool]
class ClientLocationTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Returns the user\'s current geographic location from the browser.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return '';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
