<?php

namespace Laravel\Ai\Responses;

class StoreResponse
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly bool $ready = true,
    ) {}
}
