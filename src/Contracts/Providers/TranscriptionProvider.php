<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Responses\TranscriptionResponse;

interface TranscriptionProvider
{
    /**
     * Generate audio from the given text.
     */
    public function transcribe(
        TranscribableAudio $audio,
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

    /**
     * Get the provider's configuration.
     */
    public function config(): array;

    /**
     * Get the name of the provider.
     */
    public function name(): string;

    /**
     * Get the driver name of the provider.
     */
    public function driver(): string;
}
