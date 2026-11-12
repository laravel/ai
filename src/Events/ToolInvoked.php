<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float  $time  Wall time spent in the tool's handler, in milliseconds.
     * @param  string|null  $parentInvocationId  The invocation that delegated this run through a tool, null for a root run.
     * @param  string|null  $parentToolInvocationId  The tool invocation that delegated this run, null for a root run.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public mixed $result,
        public float $time,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
    ) {}
}
