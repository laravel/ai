<?php

namespace Laravel\Ai\Mcp;

class ToolResult
{
    public function __construct(
        public readonly array $content = [],
        public readonly mixed $structuredContent = null,
        public readonly bool $isError = false,
        public readonly array $meta = [],
    ) {}

    /**
     * Create a tool result from an MCP tools/call result.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: $data['content'] ?? [],
            structuredContent: $data['structuredContent'] ?? null,
            isError: $data['isError'] ?? false,
            meta: $data['_meta'] ?? [],
        );
    }

    /**
     * Convert the result to text suitable for a model tool result.
     */
    public function toText(): string
    {
        $text = collect($this->content)
            ->filter(fn ($item) => is_array($item) && ($item['type'] ?? null) === 'text')
            ->pluck('text')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->implode("\n");

        if ($text !== '') {
            return $text;
        }

        if (! is_null($this->structuredContent)) {
            return json_encode($this->structuredContent, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return json_encode($this->content, JSON_UNESCAPED_SLASHES) ?: '';
    }
}
