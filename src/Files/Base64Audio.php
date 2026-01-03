<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class Base64Audio extends Audio implements StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public function __construct(public string $base64, public ?string $mime = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the storable display name of the file.
     */
    public function storableName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return Transcription::of($this);
    }

    /**
     * Get the raw representation of the audio for transcription.
     */
    public function transcribableContent(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the MIME type for transcription.
     */
    public function transcribableMimeType(): ?string
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
