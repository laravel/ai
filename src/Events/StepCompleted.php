<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;

class StepCompleted
{
    /**
     * @param  string  $model  the model the step was requested against, which the responding model reported in $response->meta may differ from
     * @param  float  $time  wall time spent in the provider call, in milliseconds
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
    ) {}
}
