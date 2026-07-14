<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\Concerns\HasTranscript;

abstract class Prompt
{
    use HasTranscript;

    /** @var Message[]|string */
    public readonly array|string $prompt;

    /**
     * @param  Message[]|string  $prompt
     */
    public function __construct(
        array|string $prompt,
        public readonly TextProvider $provider,
        public readonly string $model
    ) {
        $this->prompt = is_array($prompt) ? Transcript::normalize($prompt) : $prompt;
    }

    /**
     * Get the transcript history preceding the trailing user message.
     *
     * @return Message[]
     */
    public function history(): array
    {
        return $this->hasTranscript() ? array_slice($this->prompt, 0, -1) : [];
    }
}
