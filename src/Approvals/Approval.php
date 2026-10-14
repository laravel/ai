<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    /**
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?array $schema = null,
        public readonly ?array $data = null,
    ) {}

    /**
     * Create a required approval, optionally carrying a payload for the client to render.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function required(?string $reason = null, ?array $data = null): self
    {
        return new self($reason, data: $data);
    }

    /**
     * Get the keys a submission must provide.
     *
     * @return array<int, string>
     */
    public function requiredKeys(): array
    {
        return $this->schema['required'] ?? [];
    }
}
