<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\VoiceGateway;

trait HasVoiceGateway
{
    protected VoiceGateway $voiceGateway;

    /**
     * Get the provider's voice gateway.
     */
    public function voiceGateway(): VoiceGateway
    {
        return $this->voiceGateway ?? $this->gateway;
    }

    /**
     * Set the provider's voice gateway.
     */
    public function useVoiceGateway(VoiceGateway $gateway): self
    {
        $this->voiceGateway = $gateway;

        return $this;
    }
}
