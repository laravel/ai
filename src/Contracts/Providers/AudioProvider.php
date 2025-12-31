<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Responses\AudioResponse;

interface AudioProvider
{
    /**
     * Generate audio from the given text.
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
    ): AudioResponse;

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway;

    /**
     * Set the provider's audio gateway.
     */
    public function useAudioGateway(AudioGateway $gateway): self;

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string;
}
