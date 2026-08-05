<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Throwable;

class ToolFailed
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float|null  $durationMs  wall time spent in the tool's handler, or null when the call was not measured
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public Throwable $exception,
        public ?float $durationMs = null,
    ) {}
}
