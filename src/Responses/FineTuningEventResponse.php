<?php

namespace Laravel\Ai\Responses;

class FineTuningEventResponse
{
    public function __construct(
        public string $id,
        public string $type,
        public string $message,
        public int $createdAt,
        public ?string $level = null,
    ) {}
}
