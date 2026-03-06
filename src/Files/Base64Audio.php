<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\UploadedFile;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class Base64Audio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public ?string $mime = null;

    public function __construct(public string $base64, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Create a new instance from an uploaded file.
     */
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): self
    {
        return new static(
            base64_encode($file->getContent()),
            mimeType: $mimeType ?? $file->getClientMimeType(),
        );
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
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
            'type' => 'base64-audio',
            'name' => $this->name,
            'base64' => $this->base64,
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
