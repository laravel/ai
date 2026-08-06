<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Streaming\Events\Error;
use Throwable;

class StepFailed
{
    /**
     * @param  float  $time  wall time spent in the provider call before it failed, in milliseconds
     * @param  Error|null  $error  the stream error that ended the step, when the provider reported one instead of throwing
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public Throwable $exception,
        public float $time,
        public ?Error $error = null,
    ) {}
}
