<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Approvals\PendingApproval;

interface ResolvesPendingApprovals
{
    /**
     * Get the tool calls the given conversation's newest turn is still waiting on.
     *
     * @return list<PendingApproval>
     */
    public function pendingApprovalsFor(string $conversationId): array;
}
