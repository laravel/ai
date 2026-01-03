<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Files\TranscribableAudio;

class QueuedTranscriptionPrompt
{
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly array|string|null $provider,
        public readonly ?string $model,
    ) {}

    /**
     * Determine if the transcription is diarized.
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
