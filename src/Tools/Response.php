<?php

namespace Laravel\Ai\Tools;

use Stringable;

class Response implements Stringable
{
    /**
     * Create a new tool response.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public readonly Stringable|string $text,
        public readonly ?array $meta = null,
    ) {}

    /**
     * Get the text the model reads.
     */
    public function __toString(): string
    {
        return (string) $this->text;
    }
}
