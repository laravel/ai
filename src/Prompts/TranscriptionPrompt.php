<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

class TranscriptionPrompt
{
    public function __construct(
        public readonly TranscribableAudio|UploadedFile $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly TranscriptionProvider $provider,
        public readonly string $model,
    ) {}

    /**
     * Determine if the transcription is diarized.
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
