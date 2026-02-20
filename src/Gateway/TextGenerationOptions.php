<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use ReflectionClass;

class TextGenerationOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
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

        return new self(
            maxSteps: method_exists($agent, 'maxSteps')
                ? $agent->maxSteps()
                : (! empty($maxSteps) ? $maxSteps[0]->newInstance()->value : null),
            maxTokens: method_exists($agent, 'maxTokens')
                ? $agent->maxTokens()
                : (! empty($maxTokens) ? $maxTokens[0]->newInstance()->value : null),
            temperature: method_exists($agent, 'temperature')
                ? $agent->temperature()
                : (! empty($temperature) ? $temperature[0]->newInstance()->value : null),
        );
    }
}
