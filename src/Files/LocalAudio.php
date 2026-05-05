<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\InlineFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\EncodesContentToBase64;
use Laravel\Ai\Files\Concerns\SerializesInlineContent;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;
use RuntimeException;

class LocalAudio extends Audio implements Arrayable, InlineFile, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider, EncodesContentToBase64, SerializesInlineContent;

    public function __construct(public string $path, ?string $mimeType = null)
    {
        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("File does not exist at path [{$this->path}]");
        }

        return $content;
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
    public function mimeType(): string
    {
        return $this->mime
            ?? ((new Filesystem)->mimeType($this->path) ?: null)
            ?? static::DEFAULT_INLINE_MIME_TYPE;
    }

    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return Transcription::of($this);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'local-audio',
            'name' => $this->name(),
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
