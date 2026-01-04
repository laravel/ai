<?php

namespace Laravel\Ai\Responses\Data;

class StoreFileCounts
{
    public function __construct(
        public readonly int $completed,
        public readonly int $pending,
        public readonly int $failed,
    ) {}
}
