<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\RealtimeGateway;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\RealtimeSession;

interface RealtimeProvider extends Provider
{
    /**
     * Create an ephemeral realtime session.
     */
    public function createRealtimeSession(RealtimePrompt $prompt): RealtimeSession;

    /**
     * Get the provider's realtime gateway.
     */
    public function realtimeGateway(): RealtimeGateway;

    /**
     * Set the provider's realtime gateway.
     */
    public function useRealtimeGateway(RealtimeGateway $gateway): self;

    /**
     * Get the name of the default realtime model.
     */
    public function defaultRealtimeModel(): string;

    /**
     * Get the name of the default realtime voice.
     */
    public function defaultRealtimeVoice(): string;
}
