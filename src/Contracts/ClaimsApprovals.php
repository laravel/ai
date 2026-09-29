<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Exceptions\ApprovalMismatchException;

interface ClaimsApprovals
{
    /**
     * Claim the conversation's paused turn for one resume, so a duplicate resume cannot run its approved tools again.
     *
     * @throws ApprovalMismatchException when another resume holds the claim
     */
    public function claimApprovals(string $conversationId, string $claimant): void;

    /**
     * Release a claim whose resume failed, so the approval can be resubmitted.
     */
    public function releaseApprovals(string $conversationId, string $claimant): void;
}
