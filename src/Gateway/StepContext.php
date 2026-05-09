<?php

namespace Laravel\Ai\Gateway;

/**
 * Per-call context passed from the orchestrator (StepLoop) to the gateway.
 *
 * Lets the orchestrator hint provider-specific behavior the gateway alone can't infer —
 * e.g. that this is the final step in a tool loop, so a provider with synthetic
 * structured-output tooling (Bedrock) can force its toolChoice on this turn.
 */
final class StepContext
{
    public function __construct(
        public readonly int $stepNumber = 0,
        public readonly bool $isFinalStep = false,
    ) {}
}
