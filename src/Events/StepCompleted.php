<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;

class StepCompleted
{
    /**
     * @param  string  $model  The model the step was requested against, which the responding model reported in $response->meta may differ from.
     * @param  float  $time  Wall time spent in the provider call, in milliseconds.
     * @param  string|null  $parentInvocationId  The invocation that delegated this run through a tool, null for a root run.
     * @param  string|null  $parentToolInvocationId  The tool invocation that delegated this run, null for a root run.
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public StepResponse $response,
        public float $time,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
    ) {}
}
