<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function __construct(
        public string $action,
        public ?string $reason = null,
        public ?string $result = null,
        public ?array $arguments = null,
    ) {}

    public static function required(?string $reason = null): self
    {
        return new self('required', reason: $reason);
    }

    public static function notRequired(): self
    {
        return new self('not_required');
    }

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

    public function isRequired(): bool
    {
        return $this->action === 'required';
    }

    public function isNotRequired(): bool
    {
        return $this->action === 'not_required';
    }
}
