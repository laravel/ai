<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\Data\PendingToolCall;

class ToolApprovalRequested
{
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public PendingToolCall $pendingToolCall,
    ) {}
}
