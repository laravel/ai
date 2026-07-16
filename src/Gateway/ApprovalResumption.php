<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolResult;

class ApprovalResumption
{
    /**
     * @param  Message[]  $messages
     * @param  Message[]  $newMessages
     * @param  array<int, ToolResult>  $results
     * @param  array<int, string>  $failedToolCallIds
     */
    public function __construct(
        public array $messages,
        public array $newMessages,
        public bool $shouldContinue,
        public array $results,
        public array $failedToolCallIds,
    ) {}
}
