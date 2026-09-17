<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class ScoreAnswer implements Arrayable, JsonSerializable
{
    /**
     * Create a new score answer instance.
     *
     * @param  float  $score  The probability-weighted level, which may fall between two levels.
     * @param  array<int, float>  $probabilities
     * @param  array<int, string|array<string, mixed>>  $legend
     * @param  float|null  $confidence  Null when the provider cannot measure the distribution's certainty.
     */
    public function __construct(
        public readonly float $score,
        public readonly array $probabilities,
        public readonly array $legend,
        public readonly ?float $confidence = null,
    ) {}

    /**
     * Get the most probable level.
     */
    public function level(): int
    {
        return (int) array_search(max($this->probabilities), $this->probabilities, true);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'probabilities' => $this->probabilities,
            'legend' => $this->legend,
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
