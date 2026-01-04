<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Responses\Data\StoreFileCounts;

class StoreResponse
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name,
        public readonly StoreFileCounts $fileCounts,
        public readonly bool $ready,
    ) {}
}
