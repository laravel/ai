<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FileReturningTool implements Tool
{
    public function __construct(public Stringable|string $response, public string $toolName = 'FileReturningTool') {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->toolName;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool returns the latest sales chart.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->response;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
