<?php

namespace Laravel\Ai\Gateway;

final class StepContext
{
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {}
}
