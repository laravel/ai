<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    private function __construct(
        public string $action,
        public ?string $result = null,
        public ?array $arguments = null,
    ) {}

    public static function approve(): self
    {
        return new self('approve');
    }

    public static function reject(?string $result = null): self
    {
        return new self('reject', result: $result);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function edit(array $arguments): self
    {
        return new self('edit', arguments: $arguments);
    }

    public function isRejection(): bool
    {
        return $this->action === 'reject';
    }

    public function isEdit(): bool
    {
        return $this->action === 'edit';
    }
}
