<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Harness\HarnessAgent;

class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float  $time  Wall time spent in the tool's handler, in milliseconds.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent|HarnessAgent $agent,
        public Tool $tool,
        public array $arguments,
        public mixed $result,
        public float $time,
    ) {}
}
