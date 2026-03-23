<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\VideoProvider;

class VideoPrompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $seconds,
        public readonly string $size,
        public readonly VideoProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }
}
