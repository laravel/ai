<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

class StepCompleted
{
    /**
     * @param  ToolCall[]  $toolCalls
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Meta $meta,
        public Usage $usage,
        public FinishReason $finishReason,
        public array $toolCalls,
    ) {}
}
