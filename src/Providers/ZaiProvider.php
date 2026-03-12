<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use RuntimeException;

class ZaiProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        throw new RuntimeException('Streaming is not supported for Zai at the moment.');
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'glm-4.7';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'glm-4.7-flash';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'glm-5';
    }
}
