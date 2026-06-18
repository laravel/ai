<?php

namespace Laravel\Ai\Pricing;

class ModelPricing
{
    /**
     * Rates are expressed in the given currency per 1,000,000 tokens.
     *
     * A null cache/reasoning rate means those tokens are not billed separately
     * (the common case, where they are already folded into input/output by the
     * provider), so they contribute nothing to the computed cost.
     */
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public ?float $cacheRead = null,
        public ?float $cacheWrite = null,
        public ?float $reasoning = null,
        public string $currency = 'USD',
    ) {}

    /**
     * Create a pricing instance from a loosely-typed config array.
     *
     * @param  array<string, mixed>  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            input: (float) ($rates['input'] ?? 0.0),
            output: (float) ($rates['output'] ?? 0.0),
            cacheRead: isset($rates['cache_read']) ? (float) $rates['cache_read'] : null,
            cacheWrite: isset($rates['cache_write']) ? (float) $rates['cache_write'] : null,
            reasoning: isset($rates['reasoning']) ? (float) $rates['reasoning'] : null,
            currency: $rates['currency'] ?? 'USD',
        );
    }
}
