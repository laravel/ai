<?php

namespace Laravel\Ai\Mcp;

class Tool
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly ?string $title = null,
        public readonly array $icons = [],
        public readonly array $annotations = [],
        public readonly ?array $outputSchema = null,
        public readonly array $execution = [],
        public readonly array $meta = [],
    ) {}

    /**
     * Create a tool definition from an MCP tools/list item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? '',
            inputSchema: $data['inputSchema'] ?? ['type' => 'object'],
            title: $data['title'] ?? null,
            icons: $data['icons'] ?? [],
            annotations: $data['annotations'] ?? [],
            outputSchema: $data['outputSchema'] ?? null,
            execution: $data['execution'] ?? [],
            meta: $data['_meta'] ?? [],
        );
    }
}
