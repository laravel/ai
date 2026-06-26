<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Gateway\TextGenerationOptions;

class StepStarted
{
    public function __construct(
        public string $invocationId,
        public string $stepId,
        public int $stepNumber,
        public string $model,
        public ?TextGenerationOptions $options = null,
    ) {}
}
