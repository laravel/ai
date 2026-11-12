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
     * @param  string|null  $toolCallId  The id the model gave this tool call, null when the tool runs outside a model call.
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
