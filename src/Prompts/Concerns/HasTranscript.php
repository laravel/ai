<?php

namespace Laravel\Ai\Prompts\Concerns;

use Laravel\Ai\Messages\Message;

trait HasTranscript
{
    /**
     * Determine if the prompt was given as a full transcript rather than a plain string.
     */
    public function hasTranscript(): bool
    {
        return is_array($this->prompt);
    }

    /**
     * Get the trailing message of the transcript, if a transcript was given.
     */
    public function trailingMessage(): ?Message
    {
        return $this->hasTranscript() ? $this->prompt[array_key_last($this->prompt)] : null;
    }

    /**
     * Get the text of the prompt: the string itself, or the trailing user message's content.
     */
    public function text(): string
    {
        return $this->trailingMessage()?->content ?? (is_string($this->prompt) ? $this->prompt : '');
    }
}
