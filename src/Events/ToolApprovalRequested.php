<?php

namespace Laravel\Ai\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;

class ToolApprovalRequested
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Collection $pendingApprovals,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {}
}
