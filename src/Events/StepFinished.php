<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Gateway\StepResponse;

class StepFinished
{
    public function __construct(
        public string $invocationId,
        public string $stepId,
        public int $stepNumber,
        public StepResponse $response,
    ) {}
}
