<?php

namespace Laravel\Ai\Classification;

use Laravel\Ai\Contracts\Question;

final readonly class Boolean implements Question
{
    /**
     * Create a new yes / no question whose answer is the probability of "true".
     *
     * @param  string|array<string, mixed>  $instructions
     */
    public function __construct(
        public string|array $instructions,
    ) {}

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'boolean',
            'instructions' => $this->instructions,
        ];
    }
}
