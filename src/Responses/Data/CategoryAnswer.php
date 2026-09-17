<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class CategoryAnswer implements Arrayable, JsonSerializable
{
    /**
     * Create a new category answer instance.
     *
     * @param  array<string, float>  $probabilities
     * @param  float|null  $confidence  Null when the provider cannot measure the distribution's certainty.
     */
    public function __construct(
        public readonly string $category,
        public readonly array $probabilities,
        public readonly ?float $confidence = null,
    ) {}

    /**
     * Get the probability of the given option.
     */
    public function probabilityOf(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
