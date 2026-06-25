<?php

namespace Laravel\Ai\Events;

use Throwable;

class StepFailed
{
    public function __construct(
        public string $invocationId,
        public string $stepId,
        public int $stepNumber,
        public Throwable $exception,
    ) {}
}
