<?php

namespace Laravel\Ai\Events;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\Data\ToolResult;

class ToolApprovalResolved
{
    /**
     * @param  Collection<int, ToolResult>  $toolResults
     */
    public function __construct(
        public string $invocationId,
        public Agent $agent,
        public Collection $toolResults,
        public ?string $conversationId = null,
        public ?object $conversationUser = null,
    ) {}
}
