<?php

namespace Laravel\Ai\Gateway;

use BackedEnum;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\ServiceTier;
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
        public readonly ?string $serviceTier = null,
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
            return $this->agent->providerOptions(
                $provider instanceof Lab ? $provider : (Lab::tryFrom($provider) ?? $provider)
            );
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
            maxSteps: self::resolveNumeric($agent, $reflection, 'maxSteps', MaxSteps::class),
            maxTokens: self::resolveNumeric($agent, $reflection, 'maxTokens', MaxTokens::class),
            temperature: self::resolveNumeric($agent, $reflection, 'temperature', Temperature::class),
            agent: $agent,
            topP: self::resolveNumeric($agent, $reflection, 'topP', TopP::class),
            serviceTier: self::resolveString($agent, $reflection, 'serviceTier', ServiceTier::class),
        );
    }

    /**
     * Resolve a numeric option from the agent's method, falling back to the attribute.
     *
     * @param  class-string  $attribute
     */
    private static function resolveNumeric(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): int|float|null
    {
        return self::resolveValue($agent, $reflection, $method, $attribute);
    }

    /**
     * Resolve a string option from the agent's method, falling back to the attribute.
     *
     * The method or attribute may yield a raw string or any backed enum (e.g. a
     * provider service tier enum); both normalize down to the string value. An
     * empty string is treated as "unset" so it is never forwarded to a provider.
     *
     * @param  class-string  $attribute
     */
    private static function resolveString(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): ?string
    {
        $value = self::resolveValue($agent, $reflection, $method, $attribute);

        $value = $value instanceof BackedEnum ? (string) $value->value : $value;

        return $value ?: null;
    }

    /**
     * Resolve a raw option value from the agent's method, falling back to the attribute.
     *
     * @param  class-string  $attribute
     */
    private static function resolveValue(Agent $agent, ReflectionClass $reflection, string $method, string $attribute): mixed
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
