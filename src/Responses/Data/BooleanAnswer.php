<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class BooleanAnswer implements Arrayable, JsonSerializable
{
    /**
     * Create a new boolean answer instance.
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

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
