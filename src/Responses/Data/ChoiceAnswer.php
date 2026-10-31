<?php

namespace Laravel\Ai\Responses\Data;

class ChoiceAnswer extends Answer
{
    /**
     * Create a new choice answer instance.
     *
     * @param  array<string, float>  $probabilities
     * @param  float|null  $confidence  Null when the provider cannot measure the distribution's certainty.
     */
    public function __construct(
        public readonly string $choice,
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
            'choice' => $this->choice,
            'probabilities' => $this->probabilities,
            'confidence' => $this->confidence,
        ];
    }
}
