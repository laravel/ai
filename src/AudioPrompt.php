<?php

namespace Laravel\Ai;

use Illuminate\Support\Str;

class AudioPrompt
{
    public function __construct(
        public readonly string $text,
        public readonly string $voice = 'default-female',
        public readonly ?string $instructions = null,
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
