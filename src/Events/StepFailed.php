<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

class StepFailed
{
    /**
     * @param  Throwable  $exception  A StreamErrorException carries the provider's own error event on ->error.
     * @param  float  $time  Wall time spent in the provider call before it failed, in milliseconds.
     * @param  TextUsage|null  $usage  The usage the provider reported before the step failed, if any.
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
        public ?TextUsage $usage = null,
    ) {}
}
