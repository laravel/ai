<?php

namespace Laravel\Ai\Providers\Tools;

use Closure;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\SerializableClosure\SerializableClosure;

abstract class ProviderTool implements HasProviderOptions
{
    /** @var array<string, mixed>|SerializableClosure */
    protected array|SerializableClosure $providerOptions = [];

    /**
     * Attach provider-specific options to the tool payload. Closures may only capture serializable values.
     *
     * @param  array<string, mixed>|Closure(Lab|string): ?array<string, mixed>  $options
     */
    public function withProviderOptions(array|Closure $options): static
    {
        $this->providerOptions = $options instanceof Closure
            ? new SerializableClosure($options)
            : $options;

        return $this;
    }

    /**
     * Get the provider-specific options for the given provider.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($this->providerOptions instanceof SerializableClosure) {
            return ($this->providerOptions)($provider) ?: [];
        }

        return $this->providerOptions;
    }
}
