<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Enums\Lab;

class QueuedTranscriptionPrompt
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly Lab|array|string|null $provider,
        public readonly ?string $model,
        public readonly array $providerOptions = [],
    ) {}

    /**
     * Determine if the transcription is diarized.
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
