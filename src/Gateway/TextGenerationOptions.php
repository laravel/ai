<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Reasoning;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;

class TextGenerationOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?Agent $agent = null,
        public readonly ?float $topP = null,
        public readonly ?bool $reasoning = null,
    ) {
        //
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @return array<string, mixed>|null
     */
    public function providerOptions(Lab|string $provider): ?array
    {
        if ($this->agent instanceof HasProviderOptions) {
            return $this->agent->providerOptions($provider);
        }

        return null;
    }

    /**
     * Create a new TextGenerationOptions instance for the given agent.
     */
    public static function forAgent(Agent $agent): self
    {
        $reflection = new ReflectionClass($agent);

        return new self(
            maxSteps: self::resolve($agent, $reflection, 'maxSteps', MaxSteps::class),
            maxTokens: self::resolve($agent, $reflection, 'maxTokens', MaxTokens::class),
            temperature: self::resolve($agent, $reflection, 'temperature', Temperature::class),
            agent: $agent,
            topP: self::resolve($agent, $reflection, 'topP', TopP::class),
            reasoning: self::resolveReasoning($agent, $reflection),
        );
    }

    /**
     * Resolve an option from the agent's method, falling back to the attribute.
     *
     * @param  class-string  $attribute
     */
    private static function resolve(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): int|float|null
    {
        if (method_exists($agent, $method)) {
            try {
                $value = $agent->{$method}();
            } catch (\ArgumentCountError|\Error) {
                $value = null;
            }

            if (! is_null($value)) {
                return $value;
            }
        }

        $attributes = $reflection->getAttributes($attribute);

        return ! empty($attributes) ? $attributes[0]->newInstance()->value : null;
    }

    /**
     * Resolve the reasoning preference from a `reasoning()` method or a `#[Reasoning]` attribute.
     */
    private static function resolveReasoning(Agent $agent, ReflectionClass $reflection): ?bool
    {
        if (method_exists($agent, 'reasoning')) {
            try {
                $value = $agent->reasoning();
            } catch (\ArgumentCountError|\Error) {
                $value = null;
            }

            if (! is_null($value)) {
                return (bool) $value;
            }
        }

        $attributes = $reflection->getAttributes(Reasoning::class);

        return ! empty($attributes) ? $attributes[0]->newInstance()->enabled : null;
    }
}
