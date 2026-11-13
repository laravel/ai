<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float  $time  Wall time spent in the tool's handler, in milliseconds.
     * @param  string|null  $toolCallId  The tool call's id in the step response, as passed to the tool's `Request::toolCallId()`.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public mixed $result,
        public float $time,
        public ?string $toolCallId = null,
    ) {}
}
