<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    public function __construct(public readonly ?string $reason = null) {}

    /**
     * Create a required approval.
     */
    public static function required(?string $reason = null): self
    {
        return new self($reason);
    }
}
