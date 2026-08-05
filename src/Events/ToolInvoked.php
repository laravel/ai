<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;

class ToolInvoked
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float|null  $durationMs  wall time spent in the tool's handler; optional only to keep the existing constructor signature callable, the gateway always measures it
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public mixed $result,
        public ?float $durationMs = null,
    ) {}
}
