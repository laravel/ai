<?php

namespace Laravel\Ai\Contracts\Files;

use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Stringable;

interface TranscribableAudio extends Stringable
{
    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration;

    /**
     * Get the raw representation of the audio for transcription.
     */
    public function transcribableContent(): string;

    /**
     * Get the MIME type for transcription.
     */
    public function transcribableMimeType(): ?string;
}
