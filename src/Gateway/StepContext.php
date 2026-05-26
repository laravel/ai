<?php

namespace Laravel\Ai\Gateway;

/**
 * Per-turn context {@see TextGenerationLoop} passes into the gateway: which step this
 * is, whether it's the last one, and any stateful-continuation handle the
 * gateway returned from a prior turn.
 */
final class StepContext
{
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
        public readonly ?string $previousResponseId = null,
    ) {}
}
