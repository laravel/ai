<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Responses\AudioResponse;

interface AudioProvider extends Provider
{
    /**
     * Generate audio from the given text.
     *
     * @param  array<string, string>  $headers
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
        int $timeout = 30,
        array $headers = [],
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
