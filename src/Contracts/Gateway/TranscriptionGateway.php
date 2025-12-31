<?php

namespace Laravel\Ai\Contracts\Gateway;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Messages\Attachments\TranscribableAudio;
use Laravel\Ai\Responses\TranscriptionResponse;

interface TranscriptionGateway
{
    /**
     * Generate text from the given audio.
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
    ): TranscriptionResponse;
}
