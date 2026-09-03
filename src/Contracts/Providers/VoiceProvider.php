<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\VoiceGateway;
use Laravel\Ai\Responses\VoicesResponse;

interface VoiceProvider extends Provider
{
    /**
     * List the voices available for audio generation.
     */
    public function voices(int $timeout = 30): VoicesResponse;

    /**
     * Get the provider's voice gateway.
     */
    public function voiceGateway(): VoiceGateway;

    /**
     * Set the provider's voice gateway.
     */
    public function useVoiceGateway(VoiceGateway $gateway): self;
}
