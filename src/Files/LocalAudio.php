<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasLocalContent;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class LocalAudio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;
    use HasLocalContent;

    public function __construct(public string $path, ?string $mimeType = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Audio file path cannot be empty.');
        }

        $this->mime = $mimeType;
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
}
