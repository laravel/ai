<?php

namespace Laravel\Ai\Storage;

class RecordedTurn
{
    protected bool $stepped = false;

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
}
