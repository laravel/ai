<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Providers\TextProvider;

class StartingStep
{
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
    ) {}
}
