<?php

namespace Laravel\Ai\Storage;

use Throwable;

class RecordedTurn
{
    protected bool $stepped = false;

    protected ?Throwable $exception = null;

    public function __construct(
        public readonly ?string $invocationId,
        public readonly string $assistantMessageId,
        public readonly ?string $userMessageId = null,
        public readonly bool $startedConversation = false,
    ) {}

    /**
     * Note that a step has landed on the turn's row.
     */
    public function markStepped(): void
    {
        $this->stepped = true;
    }

    /**
     * Determine whether a step has landed on the turn's row.
     */
    public function hasSteps(): bool
    {
        return $this->stepped;
    }

    /**
     * Note the failure the attempt on these rows died with, so a retry can close the row it abandons.
     */
    public function markFailed(Throwable $exception): void
    {
        $this->exception = $exception;
    }

    /**
     * Get the failure the attempt on these rows died with, if it has ended.
     */
    public function failure(): ?Throwable
    {
        return $this->exception;
    }
}
