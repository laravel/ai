<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ToolChoice;
use ReflectionClass;

class TextGenerationOptions
{
    public function __construct(
        public readonly ?int $maxSteps = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?Agent $agent = null,
        public readonly ?float $topP = null,
        public readonly ?ToolChoice $toolChoice = null,
    ) {
        //
    }

    /**
     * Get the agent's middleware to run around each generation step.
     */
    public function middleware(): array
    {
        return $this->agent instanceof HasMiddleware
            ? $this->agent->middleware()
            : [];
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @return array<string, mixed>|null
     */
    public function providerOptions(Lab|string $provider): ?array
    {
        if ($this->agent instanceof HasProviderOptions) {
            return $this->agent->providerOptions(
                $provider instanceof Lab ? $provider : (Lab::tryFrom($provider) ?? $provider)
            );
        }

        return null;
    }

    /**
     * Resolve the options for the given step, releasing a forced tool choice after the first step so the model can answer.
     */
    public function forStep(int $stepNumber): self
    {
        if ($stepNumber === 0 || $this->toolChoice === null) {
            return $this;
        }

        if (! in_array($this->toolChoice->mode, [ToolChoice::required, ToolChoice::tool], true)) {
            return $this;
        }

        return new self(
            maxSteps: $this->maxSteps,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            agent: $this->agent,
            topP: $this->topP,
            toolChoice: null,
        );
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
            toolChoice: self::resolveToolChoice($agent, $reflection),
        );
    }

    /**
     * Resolve the tool choice from the agent's method, falling back to the attribute.
     */
    private static function resolveToolChoice(Agent $agent, ReflectionClass $reflection): ?ToolChoice
    {
        if (method_exists($agent, 'toolChoice')) {
            try {
                $value = $agent->toolChoice();
            } catch (\ArgumentCountError|\Error) {
                $value = null;
            }

            if (! is_null($value)) {
                return ToolChoice::from($value);
            }
        }

        $attributes = $reflection->getAttributes(ToolChoice::class);

        return ! empty($attributes) ? $attributes[0]->newInstance() : null;
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
}
