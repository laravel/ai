<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;

class EmbeddingsPrompt
{
    public function __construct(
        public readonly array $inputs,
        public readonly int $dimensions,
        public readonly EmbeddingProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if any of the inputs contain the given string.
     */
    public function contains(string $string): bool
    {
        foreach ($this->inputs as $input) {
            if (Str::contains($input, $string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the number of inputs in the prompt.
     */
    public function count(): int
    {
        return count($this->inputs);
    }
}
