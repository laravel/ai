<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use ReflectionClass;

class TextGenerationOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?array $providerOptions = null,
    ) {
        //
    }

    /**
     * Create a new TextGenerationOptions instance for the given agent.
     */
    public static function forAgent(Agent $agent): self
    {
        $reflection = new ReflectionClass($agent);

        $maxSteps = $reflection->getAttributes(MaxSteps::class);
        $maxTokens = $reflection->getAttributes(MaxTokens::class);
        $temperature = $reflection->getAttributes(Temperature::class);

        $providerOptions = $agent instanceof HasProviderOptions
            ? $agent->providerOptions()
            : null;

        return new self(
            maxSteps: ! empty($maxSteps) ? $maxSteps[0]->newInstance()->value : null,
            maxTokens: ! empty($maxTokens) ? $maxTokens[0]->newInstance()->value : null,
            temperature: ! empty($temperature) ? $temperature[0]->newInstance()->value : null,
            providerOptions: $providerOptions,
        );
    }
}
