<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

interface TextProvider extends Provider
{
    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse;

    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse;

    /**
     * Set the provider's text gateway.
     */
    public function useTextGateway(StepTextGateway $gateway): self;

    /**
     * Get the multi-step text generation loop wrapping the provider's text gateway.
     */
    public function textGenerationLoop(): TextGenerationLoop;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string;

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string;

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string;
}
