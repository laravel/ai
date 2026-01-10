<?php

namespace Laravel\Ai\Responses\Data;

use Stringable;

class RankedDocument implements Stringable
{
    /**
     * Create a new ranked document instance.
     */
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
    ) {}

    /**
     * Get the document content.
     */
    public function __toString(): string
    {
        return $this->document;
    }
}
