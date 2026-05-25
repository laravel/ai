<?php

namespace Tests\Fixtures\Mcp;

use Stringable;

class FakeMcpToolResult implements Stringable
{
    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $structuredContent
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public array $content,
        public bool $isError,
        public ?array $structuredContent = null,
        public ?array $meta = null,
    ) {}

    public function text(): string
    {
        $text = [];

        foreach ($this->content as $item) {
            if (($item['type'] ?? null) === 'text' && is_string($item['text'] ?? null)) {
                $text[] = $item['text'];
            }
        }

        return implode('', $text);
    }

    public function __toString(): string
    {
        return $this->text();
    }
}
