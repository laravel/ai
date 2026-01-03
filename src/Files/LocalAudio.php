<?php

namespace Laravel\Ai\Files;

use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class LocalAudio extends Audio implements StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public function __construct(public string $path, public ?string $mime = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return file_get_contents($this->path);
    }

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return file_get_contents($this->path);
    }

    /**
     * Get the storable display name of the file.
     */
    public function storableName(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return $this->mime ?? (new Filesystem)->mimeType($this->path);
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
        return file_get_contents($this->path);
    }

    /**
     * Get the MIME type for transcription.
     */
    public function transcribableMimeType(): ?string
    {
        return $this->mime ?? (new Filesystem)->mimeType($this->path);
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
