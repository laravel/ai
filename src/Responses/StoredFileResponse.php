<?php

namespace Laravel\Ai\Responses;

class StoredFileResponse
{
    public function __construct(
        public string $id,
    ) {}
}
