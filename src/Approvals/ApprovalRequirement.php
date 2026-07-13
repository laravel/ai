<?php

namespace Laravel\Ai\Approvals;

class ApprovalRequirement
{
    private function __construct(
        public bool $required,
        public ?string $reason = null,
    ) {}

    public static function required(?string $reason = null): self
    {
        return new self(true, $reason);
    }

    public static function notRequired(): self
    {
        return new self(false);
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}
