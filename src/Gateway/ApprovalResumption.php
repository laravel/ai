<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

class ApprovalResumption
{
    /**
     * @param  Message[]  $messages
     * @param  Message[]  $newMessages
     * @param  array<int, ToolResult>  $results
     * @param  Throwable|null  $failure  the first exception an approved tool threw, rethrown once the results are recorded unless the agent recovers from tool failures
     */
    public function __construct(
        public array $messages,
        public array $newMessages,
        public array $results,
        public bool $shouldContinue,
        public ?Throwable $failure = null,
    ) {}
}
