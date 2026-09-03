<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\VoiceProvider;
use Laravel\Ai\Responses\VoicesResponse;

interface VoiceGateway
{
    /**
     * List the voices available for audio generation.
     */
    public function listVoices(
        VoiceProvider $provider,
        int $timeout = 30,
    ): VoicesResponse;
}
