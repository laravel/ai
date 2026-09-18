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
     */
    public function __construct(
        public array $messages,
        public array $newMessages,
        public array $results,
        public bool $shouldContinue,
    ) {}
}
