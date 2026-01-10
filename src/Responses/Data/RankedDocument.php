<?php

namespace Laravel\Ai\Responses\Data;

class RankedDocument
{
    /**
     * Create a new ranked document instance.
     */
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
    ) {}
}
