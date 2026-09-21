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
     * Create a pause waiting on the values the given schema describes.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>|null  $data
     */
    public static function input(array $schema, ?array $data = null): self
    {
        return new self(schema: $schema, data: $data);
    }

    /**
     * Determine whether the pause is waiting on values rather than a decision.
     */
    public function isInteractive(): bool
    {
        return $this->schema !== null;
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
