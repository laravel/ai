<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

class TranscriptionPrompt
{
    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly TranscriptionProvider $provider,
        public readonly string $model,
        public readonly ?int $timeout = null,
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
