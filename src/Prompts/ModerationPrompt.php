<?php

namespace Laravel\Ai\Prompts;

use Countable;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\ModerationProvider;

class ModerationPrompt implements Countable
{
    public function __construct(
        public readonly string|array $input,
        public readonly ModerationProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the input contains the given string.
     */
    public function contains(string $string): bool
    {
        if (is_string($this->input)) {
            return Str::contains($this->input, $string);
        }

        return array_any($this->input, fn ($input) => Str::contains($input, $string));
    }

    /**
     * Get the number of inputs in the prompt.
     */
    public function count(): int
    {
        return is_string($this->input) ? 1 : count($this->input);
    }
}
