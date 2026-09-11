<?php

namespace Laravel\Ai\Approvals;

class Approval
{
    /**
     * @param  array<string, mixed>|null  $ui
     */
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?array $ui = null,
    ) {}

    /**
     * Create a required approval, answered with an approval or a rejection.
     */
    public static function required(?string $reason = null): self
    {
        return new self($reason);
    }

    /**
     * Create an approval carrying a payload for the client, answered with a submission.
     *
     * @param  array<string, mixed>  $ui
     */
    public static function input(array $ui, ?string $reason = null): self
    {
        return new self($reason, $ui);
    }

    /**
     * Determine whether the pause is waiting on a client submission.
     */
    public function isInteractive(): bool
    {
        return $this->ui !== null;
    }
}
