<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Gateway\MiniMaxGateway;

class MiniMaxProvider extends Provider implements AudioProvider
{
    use Concerns\GeneratesAudio;
    use Concerns\HasAudioGateway;

    public function __construct(
        protected array $config,
        protected Dispatcher $events) {}

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new MiniMaxGateway;
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'speech-2.8-hd';
    }
}
