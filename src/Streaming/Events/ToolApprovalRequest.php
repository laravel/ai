<?php

namespace Laravel\Ai\Streaming\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;

class ToolApprovalRequest extends StreamEvent
{
    /**
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     * @param  array<int, array<string, mixed>>  $providerContentBlocks  raw provider replay state for the paused turn; never serialized to clients
     * @param  array<int, array{tool_call_ids: array<int, string>, blocks: array<int|string, mixed>, content: string}>  $providerContentBlockSteps  per-step replay state for the paused turn; never serialized to clients
     */
    public function __construct(
        public string $id,
        public Collection $pendingApprovals,
        public int $timestamp,
        public array $providerContentBlocks = [],
        public array $providerContentBlockSteps = [],
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
