<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolResult;

class ApprovalResumption
{
    /**
     * @param  Message[]  $messages
     * @param  array<int, ToolResult>  $results
     * @param  array<int, string>  $rejectedToolCallIds
     * @param  array<int, string>  $failedToolCallIds
     */
    public function __construct(
        public array $messages,
        public int $originalMessageCount,
        public bool $shouldContinue,
        public array $results,
        public array $rejectedToolCallIds,
        public array $failedToolCallIds,
    ) {}
}
