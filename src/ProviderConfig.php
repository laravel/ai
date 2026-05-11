<?php

namespace Laravel\Ai;

use Laravel\Ai\Enums\Lab;
use LogicException;

final class ProviderConfig
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $name,
        protected array $config = [],
    ) {}

    /**
     * Build a runtime configuration for the given provider name.
     *
     * @param  array<string, mixed>  $config
     */
    public static function for(string|Lab $name, array $config = []): self
    {
        unset($config['name']);

        return new self(
            $name instanceof Lab ? $name->value : $name,
            $config,
        );
    }

    /**
     * Get the provider name this configuration is for.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the values to merge over the provider's base configuration.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return $this->config;
    }

    /**
     * Refuse to serialize so credentials cannot be persisted into queues, caches, or sessions.
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'A runtime provider configuration must not be serialized. Credentials cannot be persisted into queue payloads, caches, or sessions.'
        );
    }
}
