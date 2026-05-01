<?php

namespace Laravel\Ai\Messages;

class Conversation
{
    public function __construct(
        public string $id,
        public string $title,
        public string|int|null $userId = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
