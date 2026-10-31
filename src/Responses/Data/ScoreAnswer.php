<?php

namespace Laravel\Ai\Responses\Data;

class ScoreAnswer extends Answer
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
        return $this->probabilities === []
            ? (int) round($this->score)
            : (int) array_search(max($this->probabilities), $this->probabilities, true);
    }

    /**
     * Get the description of the most probable level.
     *
     * @return string|array<string, mixed>|null
     */
    public function label(): string|array|null
    {
        return $this->legend[$this->level()] ?? null;
    }

    /**
     * Get the score as a fraction of the highest level.
     */
    public function normalized(): float
    {
        return count($this->legend) > 1 ? $this->score / (count($this->legend) - 1) : 0.0;
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
}
