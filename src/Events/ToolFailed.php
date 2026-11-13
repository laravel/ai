<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Throwable;

class ToolFailed
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  float  $time  Wall time spent in the tool's handler before it threw, in milliseconds.
     * @param  string|null  $toolCallId  The tool call's id in the step response, as passed to the tool's `Request::toolCallId()`.
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public Agent $agent,
        public Tool $tool,
        public array $arguments,
        public Throwable $exception,
        public float $time,
        public ?string $toolCallId = null,
    ) {}
}
