<?php

namespace Tests\Fixtures\Mcp;

use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Client\Schema\ToolResult;

class FakeMcpTool extends Tool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>|null  $outputSchema
     * @param  array<string, mixed>  $annotations
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        protected FakeMcpClient $fakeClient,
        string $name,
        ?string $title,
        ?string $description,
        array $inputSchema,
        ?array $outputSchema = null,
        array $annotations = [],
        ?array $meta = null,
    ) {
        parent::__construct(null, $name, $title, $description, $inputSchema, $outputSchema, $annotations, $meta);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $arguments = []): ToolResult
    {
        return $this->fakeClient->callTool($this->name, $arguments);
    }
}
