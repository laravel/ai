<?php

namespace Laravel\Ai\Files;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;
use RuntimeException;

class StoredAudio extends Audio implements StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public function __construct(public string $path, public ?string $disk = null) {}

    /**
     * Get the raw representation of the file.
     */
    public function storableContent(): string
    {
        return Storage::disk($this->disk)->get($this->path) ??
            throw new RuntimeException('File ['.$this->path.'] does not exist on disk ['.$this->disk.'].');
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
        return Storage::disk($this->disk)->mimeType($this->path);
    }

    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return Transcription::of($this);
    }

    /**
     * Get the Base64 representation of the audio for transcription.
     */
    public function transcribableContent(): string
    {
        return base64_encode($this->storableContent());
    }

    /**
     * Get the MIME type for transcription.
     */
    public function transcribableMimeType(): ?string
    {
        return $this->storableMimeType();
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
