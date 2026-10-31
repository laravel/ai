<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\Step;

class ToolApprovalRequest extends StreamEvent
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     * @param  Collection<int, Step>  $steps  replay state for the paused turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public Collection $pendingApprovals,
        public int $timestamp,
        public Collection $steps = new Collection,
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
