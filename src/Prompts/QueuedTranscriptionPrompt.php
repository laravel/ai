<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;

class QueuedTranscriptionPrompt
{
    public function __construct(
        public readonly TranscribableAudio|UploadedFile $audio,
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
