<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    public function __construct(
        public ?string $reason = null,
    ) {}

    public static function required(?string $reason = null): self
    {
        return new self($reason);
    }
}
