<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Ai;
use Laravel\Ai\Responses\VoicesResponse;

trait ListsVoices
{
    /**
     * List the voices available for audio generation.
     */
    public function voices(int $timeout = 30): VoicesResponse
    {
        if (Ai::voicesAreFaked()) {
            Ai::recordVoiceListing($this->name());
        }

        return $this->voiceGateway()->listVoices($this, $timeout);
    }
}
