<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;

class Base64Audio extends Audio implements StorableFile, TranscribableAudio
{
    public function __construct(public string $base64, public ?string $mime = null) {}

    /**
     * Get the Base64 representation of the audio for transcription.
     */
    public function toBase64ForTranscription(): string
    {
        return $this->base64;
    }

    /**
     * Get the MIME type for transcription.
     */
    public function mimeTypeForTranscription(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Set the audio's MIME type.
     */
    public function withMime(string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
