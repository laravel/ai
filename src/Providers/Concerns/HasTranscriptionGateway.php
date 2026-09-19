<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;

trait HasTranscriptionGateway
{
    protected TranscriptionGateway $transcriptionGateway;

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway;
    }

    /**
     * Set the provider's transcription gateway.
     */
    public function useTranscriptionGateway(TranscriptionGateway $gateway): self
    {
        $this->transcriptionGateway = $gateway;

        return $this;
    }
}
