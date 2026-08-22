<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\RealtimeSession;

trait GeneratesRealtimeSessions
{
    /**
     * Create an ephemeral realtime session.
     */
    public function createRealtimeSession(RealtimePrompt $prompt): RealtimeSession
    {
        if (Ai::realtimeIsFaked()) {
            Ai::recordRealtimeSessionCreation($prompt);
        }

        return $this->realtimeGateway()->createRealtimeSession($this, $prompt);
    }
}
