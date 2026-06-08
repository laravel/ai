<?php

namespace Tests\Fixtures\Mcp;

class FakeMcpTool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>|null  $outputSchema
     * @param  array<string, mixed>  $annotations
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        protected FakeMcpClient $client,
        public readonly string $name,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly array $inputSchema,
        public readonly ?array $outputSchema = null,
        public readonly array $annotations = [],
        public readonly ?array $meta = null,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(array $arguments = []): FakeMcpToolResult
    {
        return $this->client->callTool($this->name, $arguments);
    }
}
