<?php

namespace Laravel\Ai\Responses;

class CreatedStoreResponse
{
    public function __construct(
        public readonly string $id,
    ) {}
}
