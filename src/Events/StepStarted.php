<?php

namespace Laravel\Ai\Events;

class StepStarted
{
    public function __construct(
        public string $invocationId,
        public string $stepId,
        public int $stepNumber,
    ) {}
}
