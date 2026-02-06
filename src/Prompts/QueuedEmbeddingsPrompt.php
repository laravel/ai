<?php

namespace Laravel\Ai\Prompts;

use Countable;

class QueuedEmbeddingsPrompt implements Countable
{
    public function __construct(
        public readonly array $inputs,
        public readonly ?int $dimensions,
        public readonly array|string|null $provider,
        public readonly ?string $model,
    ) {}

    /**
     * Determine if any of the inputs contain the given string.
     */
    public function contains(string $string): bool
    {
        return array_any($this->inputs, fn ($input) => str_contains($input, $string));
    }

    /**
     * Get the number of inputs in the prompt.
     */
    public function count(): int
    {
        return count($this->inputs);
    }
}
