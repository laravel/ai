<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Harness\HarnessAgent;

class InvokingTool
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent|HarnessAgent $agent,
        public Tool $tool,
        public array $arguments,
    ) {}
}
