<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\ModerationProvider;

class ModerationPrompt
{
    /**
     * Create a new moderation prompt instance.
     */
    public function __construct(
        public readonly string $input,
        public readonly ModerationProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the input contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->input, $string);
    }
}
