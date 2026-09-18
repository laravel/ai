<?php

namespace Laravel\Ai\Classification;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Question;

final readonly class Choice implements Question
{
    /**
     * Create a new single-choice question whose answer is one of the given options.
     *
     * @param  string|array<string, mixed>  $instructions
     * @param  array<string, string|array<string, mixed>|null>  $options  Option names mapped to an optional description.
     *
     * @throws InvalidArgumentException if fewer than two options are given or any option name is not a string.
     */
    public function __construct(
        public string|array $instructions,
        public array $options,
    ) {
        if (count($options) < 2) {
            throw new InvalidArgumentException('A choice question requires at least two options.');
        }

        foreach (array_keys($options) as $option) {
            if (! is_string($option)) {
                throw new InvalidArgumentException('Choice options must be keyed by option name.');
            }
        }
    }

    /**
     * Get the question as a provider-neutral array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'options' => $this->options,
        ];
    }
}
