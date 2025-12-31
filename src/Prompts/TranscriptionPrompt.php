<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;

class TranscriptionPrompt
{
    public function __construct(
        public readonly TranscribableAudio|UploadedFile $audio,
        public readonly ?string $language = null,
        public readonly bool $diarize = false,
    ) {}

    /**
     * Determine if the transcription is diarized.
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
