<?php

namespace Laravel\Ai\Contracts\Providers;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Responses\TranscriptionResponse;

interface TranscriptionProvider
{
    /**
     * Generate audio from the given text.
     */
    public function transcribe(
        TranscribableAudio|UploadedFile $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
    ): TranscriptionResponse;

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway;

    /**
     * Set the provider's transcription gateway.
     */
    public function useTranscriptionGateway(TranscriptionGateway $gateway): self;

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string;
}
