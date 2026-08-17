<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;

class ToolApprovalRequest extends StreamEvent
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     * @param  array<array-key, mixed>  $providerContentBlocks  raw provider replay state for the paused turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public Collection $pendingApprovals,
        public int $timestamp,
        public array $providerContentBlocks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invocation_id' => $this->invocationId,
            'type' => 'tool_approval_request',
            'approvals' => $this->pendingApprovals->values()->toArray(),
            'timestamp' => $this->timestamp,
        ];
    }
}
