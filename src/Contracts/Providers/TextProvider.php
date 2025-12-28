<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

interface TextProvider
{
    /**
     * Invoke the given agent.
     */
    public function prompt(Agent $agent, string $prompt, array $attachments, string $model): AgentResponse;

    /**
     * Stream the response from the given agent.
     */
    public function stream(Agent $agent, string $prompt, array $attachments, string $model): StreamableAgentResponse;

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway;

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(TextGateway $gateway): self;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string;
}
