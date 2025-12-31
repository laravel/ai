<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\AudioProvider;

class AudioPrompt
{
    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly ?string $instructions,
        public readonly AudioProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the text contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->text, $string);
    }

    /**
     * Determine if the voice is male.
     */
    public function isMale(): bool
    {
        return $this->voice === 'default-male';
    }

    /**
     * Determine if the voice is female.
     */
    public function isFemale(): bool
    {
        return $this->voice === 'default-female';
    }
}
