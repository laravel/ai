<?php

namespace Laravel\Ai\Files;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasStoredContent;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class StoredAudio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;
    use HasStoredContent;

    public function __construct(public string $path, public ?string $disk = null)
    {
        if (blank($path)) {
            throw new InvalidArgumentException('Audio file path cannot be empty.');
        }
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
            'type' => 'stored-audio',
            'name' => $this->name(),
            'path' => $this->path,
            'disk' => $this->disk ?? config('filesystems.default'),
        ];
    }
}
