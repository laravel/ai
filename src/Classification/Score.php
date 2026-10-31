<?php

namespace Laravel\Ai\Classification;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Question;

final readonly class Score implements Question
{
    /**
     * Create a new ordinal question whose answer is the expected position on the given levels.
     *
     * @param  string|array<string, mixed>  $instructions
     * @param  list<string|array<string, mixed>>  $levels  Level descriptions ordered from lowest to highest.
     *
     * @throws InvalidArgumentException if the levels are not a list of at least two entries.
     */
    public function __construct(
        public string|array $instructions,
        public array $levels,
    ) {
        if (! array_is_list($levels) || count($levels) < 2) {
            throw new InvalidArgumentException('A score question requires a list of at least two levels.');
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'score',
            'instructions' => $this->instructions,
            'levels' => $this->levels,
        ];
    }
}
