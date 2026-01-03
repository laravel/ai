<?php

namespace Laravel\Ai\Contracts\Files;

interface TranscribableAudio
{
    public function toBase64ForTranscription(): string;

    public function mimeTypeForTranscription(): ?string;
}
