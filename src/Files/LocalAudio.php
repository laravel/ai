<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class LocalAudio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public ?string $mime = null;

    public function __construct(public string $path, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return file_get_contents($this->path);
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name ?? basename($this->path);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
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
     * Set the audio's MIME type.
     */
    public function withMimeType(string $mimeType): static
    {
        $this->mime = $mimeType;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-audio',
            'name' => $this->name,
            'path' => $this->path,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->content();
    }
}
