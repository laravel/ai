<?php

namespace Laravel\Ai\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Harness\HarnessAgent;

class ToolApprovalRequested
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     */
    public function __construct(
        public string $invocationId,
        public Agent|HarnessAgent $agent,
        public Collection $pendingApprovals,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {}
}
