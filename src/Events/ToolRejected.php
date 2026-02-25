<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\Data\PendingToolCall;

class ToolRejected
{
    public function __construct(
        public Agent $agent,
        public PendingToolCall $pendingToolCall,
        public ?string $reason = null,
    ) {}
}
