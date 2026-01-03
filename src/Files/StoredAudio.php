<?php

namespace Laravel\Ai\Files;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use RuntimeException;

class StoredAudio extends Audio implements StorableFile, TranscribableAudio
{
    public function __construct(public string $path, public ?string $disk = null) {}

    /**
     * Get the Base64 representation of the audio for transcription.
     */
    public function toBase64ForTranscription(): string
    {
        return base64_encode($this->StorableFileContent());
    }

    /**
     * Get the MIME type for transcription.
     */
    public function mimeTypeForTranscription(): ?string
    {
        return $this->storableMimeType();
    }

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
        return basename($this->path);
    }

    /**
     * Get the MIME type for storage.
     */
    public function storableMimeType(): ?string
    {
        return Storage::disk($this->disk)->mimeType($this->path);
    }

    public function __toString(): string
    {
        return $this->storableContent();
    }
}
