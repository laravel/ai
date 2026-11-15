<?php

namespace Laravel\Ai\Gateway;

class StepContext
{
    /** @param  string|null  $continuationToken  Provider handle the previous step returned for the next one, such as a response or container id. */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {}
}
