<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\RealtimeSession;

interface RealtimeGateway
{
    /**
     * Create an ephemeral realtime session.
     */
    public function createRealtimeSession(
        RealtimeProvider $provider,
        RealtimePrompt $prompt,
    ): RealtimeSession;
}
