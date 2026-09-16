<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?array $meta = null,
        public readonly bool $interactive = false,
    ) {}

    /**
     * Create a required approval, optionally carrying a payload for the client to render.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function required(?string $reason = null, ?array $meta = null): self
    {
        return new self($reason, $meta);
    }

    /**
     * Create a pause carrying a payload for the client, answered with a submission rather than an approval.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function input(array $meta): self
    {
        return new self(null, $meta, interactive: true);
    }

    /**
     * Determine whether the pause is waiting on a client submission.
     */
    public function isInteractive(): bool
    {
        return $this->interactive;
    }
}
