<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

class QueuedAudioPrompt
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $text,
        public readonly string $voice,
        public readonly ?string $instructions,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly int $timeout = 30,
        public readonly array $headers = [],
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
