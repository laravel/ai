<?php

namespace Laravel\Ai\Classification;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Question;

final readonly class Boolean implements Question
{
    /**
     * Create a new yes / no question whose answer is the probability of "true".
     *
     * This is TypeSafe's "noul" primitive.
     *
     * @param  string|array<string, mixed>  $instructions
     * @param  array{true?: string, false?: string}|null  $criteria  Descriptions of what a yes and a no mean.
     *
     * @throws InvalidArgumentException if the criteria describe anything but the true and false cases.
     */
    public function __construct(
        public string|array $instructions,
        public ?array $criteria = null,
    ) {
        if ($criteria !== null && array_diff(array_keys($criteria), ['true', 'false']) !== []) {
            throw new InvalidArgumentException('Boolean criteria may only describe the "true" and "false" cases.');
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => 'boolean',
            'instructions' => $this->instructions,
            'criteria' => $this->criteria,
        ], fn ($value) => $value !== null);
    }
}
