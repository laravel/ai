<?php

namespace Laravel\Ai\Prompts;

use Countable;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

class QueuedEmbeddingsPrompt implements Countable
{
    /**
     * @param  string[]  $inputs
     * @param  array<string, mixed>  $providerOptions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly array $inputs,
        public readonly ?int $dimensions,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
        public readonly array $headers = [],
    ) {}

    /**
     * Determine if any of the inputs contain the given string.
     */
    public function contains(string $string): bool
    {
        return array_any($this->inputs, fn ($input) => is_string($input) && Str::contains($input, $string));
    }

    /**
     * Get the number of inputs in the prompt.
     */
    public function count(): int
    {
        return count($this->inputs);
    }
}
