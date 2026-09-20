<?php

namespace Laravel\Ai\Responses\Data;

class BooleanAnswer extends Answer
{
    /**
     * Create a new boolean answer instance.
     *
     * Unlike a choice or a score, this answer has no separate confidence: with
     * only two outcomes, the probability describes the distribution completely.
     *
     * @param  float  $probability  The probability that the answer is "true".
     */
    public function __construct(
        public readonly float $probability,
    ) {}

    /**
     * Determine if the probability meets the given threshold.
     */
    public function isTrue(float $threshold = 0.5): bool
    {
        return $this->probability >= $threshold;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return ['probability' => $this->probability];
    }
}
