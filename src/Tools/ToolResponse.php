<?php

namespace Laravel\Ai\Tools;

use Stringable;

class ToolResponse implements Stringable
{
    protected ?array $meta = null;

    public function __construct(
        protected string $result,
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
    public function meta(array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * Get the model-facing result payload.
     */
    public function result(): string
    {
        return $this->result;
    }

    /**
     * Get the UI metadata.
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /**
     * Get the string representation (model payload only).
     */
    public function __toString(): string
    {
        return $this->result;
    }
}
