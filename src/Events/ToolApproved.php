<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\Data\PendingToolCall;

class ToolApproved
{
    public function __construct(
        public Agent $agent,
        public PendingToolCall $pendingToolCall,
    ) {}
}
