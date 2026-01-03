<?php

namespace Laravel\Ai\Contracts\Files;

use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;

interface TranscribableAudio
{
    public function transcription(): PendingTranscriptionGeneration;

    public function transcribableContent(): string;

    public function transcribableMimeType(): ?string;
}
