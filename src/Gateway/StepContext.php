<?php

namespace Laravel\Ai\Gateway;

/** @internal */
class StepContext
{
    /** @param  string|null  $continuationToken  Provider handle for stateful continuation; null for stateless providers that replay full history. */
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $continuationToken = null,
    ) {}
}
