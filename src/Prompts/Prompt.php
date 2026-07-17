<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Providers\TextProvider;

abstract class Prompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?Decisions $approvalDecisions = null,
    ) {}

    /**
     * Determine whether the prompt continues a paused tool approval.
     */
    public function isApprovalContinuation(): bool
    {
        return $this->approvalDecisions !== null;
    }
}
