<?php

namespace Laravel\Ai;

class AudioPrompt
{
    public function __construct(
        public readonly string $text,
        public readonly string $voice = 'default-female',
        public readonly ?string $instructions = null,
    ) {}

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
