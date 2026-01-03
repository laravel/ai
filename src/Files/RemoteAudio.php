<?php

namespace Laravel\Ai\Files;

use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Laravel\Ai\Files\Concerns\HasRemoteContent;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Transcription;

class RemoteAudio extends Audio implements StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider, HasRemoteContent;

    public function __construct(public string $url, public ?string $mime = null) {}

    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return Transcription::of($this);
    }
}
