<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\DeepgramGateway;

class DeepgramProvider extends Provider implements AudioProvider, TranscriptionProvider
{
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasAudioGateway;
    use Concerns\HasTranscriptionGateway;

    public function __construct(
        protected array $config,
        protected Dispatcher $events) {}

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ?? new DeepgramGateway;
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ?? new DeepgramGateway;
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return 'aura-2-thalia-en';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return 'nova-3';
    }
}
