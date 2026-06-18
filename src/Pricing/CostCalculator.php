<?php

namespace Laravel\Ai\Pricing;

use Laravel\Ai\Responses\Data\Cost;
use Laravel\Ai\Responses\Data\Usage;

class CostCalculator
{
    public function __construct(protected PriceList $prices) {}

    /**
     * Calculate the cost of the given usage for a provider and model.
     *
     * Returns an "unknown" cost (total 0, isKnown() === false) when no pricing
     * is available, so cost reporting is always safe and never throws.
     */
    public function calculate(Usage $usage, ?string $provider, ?string $model): Cost
    {
        $pricing = $this->prices->for($provider, $model);

        if ($pricing === null) {
            return Cost::unknown();
        }

        $rate = static fn (int $tokens, ?float $perMillion): float => $perMillion === null
            ? 0.0
            : $tokens / 1_000_000 * $perMillion;

        return new Cost(
            input: $rate($usage->promptTokens, $pricing->input),
            output: $rate($usage->completionTokens, $pricing->output),
            cacheRead: $rate($usage->cacheReadInputTokens, $pricing->cacheRead),
            cacheWrite: $rate($usage->cacheWriteInputTokens, $pricing->cacheWrite),
            reasoning: $rate($usage->reasoningTokens, $pricing->reasoning),
            currency: $pricing->currency,
        );
    }
}
