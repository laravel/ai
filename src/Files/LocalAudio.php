<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;
use RuntimeException;

class LocalAudio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    public function __construct(public string $path, ?string $mimeType = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Audio file path cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Get the raw representation of the file.
     *
     * @throws RuntimeException if the file does not exist at the configured path.
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
