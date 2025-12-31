<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ImagePrompt
{
    public readonly Collection $attachments;

    public function __construct(
        public readonly string $prompt,
        Collection|array $attachments = [],
        public readonly ?string $size = null,
        public readonly ?string $quality = null,
    ) {
        $this->attachments = Collection::make($attachments);
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Determine if the image generation is square.
     */
    public function isSquare(): bool
    {
        return $this->size === '1:1';
    }

    /**
     * Determine if the image generation is landscape.
     */
    public function isLandscape(): bool
    {
        return $this->size === '3:2';
    }

    /**
     * Determine if the image generation is portrait.
     */
    public function isPortrait(): bool
    {
        return $this->size === '2:3';
    }
}
