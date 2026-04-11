<?php

namespace Laravel\Ai\Tools;

use Stringable;

class ToolResponse implements Stringable
{
    public function __construct(
        public readonly string $result,
        public readonly ?array $meta = null,
    ) {}

    /**
     * Create a new tool response instance.
     */
    public static function make(string|Stringable $result): static
    {
        return new static((string) $result);
    }

    /**
     * Set the UI metadata for the tool response.
     */
    public function withMeta(array $meta): static
    {
        return new static($this->result, $meta);
    }

    /**
     * Get the string representation (model payload only).
     */
    public function __toString(): string
    {
        return $this->result;
    }
}
