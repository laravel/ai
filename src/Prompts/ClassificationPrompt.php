<?php

namespace Laravel\Ai\Prompts;

use Countable;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Question;

class ClassificationPrompt implements Countable
{
    /**
     * Create a new classification prompt instance.
     *
     * @param  string|array<string, mixed>  $state
     * @param  array<string, Question>  $questions
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        public readonly string|array $state,
        public readonly array $questions,
        public readonly ClassificationProvider $provider,
        public readonly string $model,
        public readonly int $timeout = 30,
        public readonly array $providerOptions = [],
    ) {}

    /**
     * Determine if the state contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains(is_string($this->state) ? $this->state : json_encode($this->state), $string);
    }

    /**
     * Determine if the prompt asks a question with the given key.
     */
    public function asks(string $key): bool
    {
        return array_key_exists($key, $this->questions);
    }

    /**
     * Get the number of questions in the prompt.
     */
    public function count(): int
    {
        return count($this->questions);
    }
}
